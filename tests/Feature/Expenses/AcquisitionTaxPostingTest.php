<?php

namespace Tests\Feature\Expenses;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Expenses\Actions\ApproveExpenseAction;
use App\Domains\Expenses\Actions\CreateExpenseAction;
use App\Domains\Expenses\Actions\PostExpenseAction;
use App\Domains\Expenses\DTOs\CreateExpenseData;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Expenses\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Acquisition tax (Bezugsteuer) on services bought abroad.
 *
 * The distinguishing property against ordinary input VAT is that the supplier
 * bills no VAT: the company owes the tax to the FTA and deducts it in the same
 * entry. So the bank must move the NET amount while both VAT accounts still
 * carry the tax, and the return must be able to tell the two apart.
 */
class AcquisitionTaxPostingTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['6530', 'Software and Subscriptions', AccountType::Expense],
            ['1020', 'Bank', AccountType::Asset],
            ['1170', 'Input VAT', AccountType::Asset],
            ['2202', 'Acquisition Tax Payable', AccountType::Liability],
            ['3900', 'Rounding Difference', AccountType::Revenue],
        ] as [$code, $name, $type]) {
            Account::create([
                'organization_id' => $this->org->id,
                'code' => $code,
                'name' => $name,
                'type' => $type->value,
            ]);
        }

        $this->vatRate = VatRate::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard',
            'rate' => 8.10,
            'code' => 'NORMAL',
            'is_default' => true,
        ]);
    }

    private function postExpense(string $treatment): Expense
    {
        $expense = (new CreateExpenseAction)->execute(CreateExpenseData::fromArray([
            'organization_id' => $this->org->id,
            'category' => 'Software and Subscriptions',
            'description' => 'Anthropic API',
            'amount' => 1000.00,
            'vat_rate_id' => $this->vatRate->id,
            'date' => '2026-01-15',
            'vendor' => 'Anthropic PBC',
            'tax_treatment' => $treatment,
        ]));

        $expense = (new ApproveExpenseAction)->execute($expense);

        return app(PostExpenseAction::class)->execute($expense, '6530');
    }

    private function accountId(string $code): int
    {
        return (int) Account::where('code', $code)
            ->where('organization_id', $this->org->id)
            ->value('id');
    }

    public function test_reverse_charge_leaves_the_bank_at_the_net_amount(): void
    {
        $expense = $this->postExpense(ExpenseTaxTreatment::ReverseCharge->value);

        $this->assertTrue($expense->journalEntry->isBalanced());

        $lines = $expense->journalEntry->lines;
        $this->assertCount(4, $lines);

        $bank = $lines->firstWhere('account_id', $this->accountId('1020'));
        $expenseLine = $lines->firstWhere('account_id', $this->accountId('6530'));
        $inputVat = $lines->firstWhere('account_id', $this->accountId('1170'));
        $acquisition = $lines->firstWhere('account_id', $this->accountId('2202'));

        // The foreign supplier bills no VAT, so only the net amount leaves the bank.
        $this->assertSame('1000.00', (string) $bank->credit);
        $this->assertSame('1000.00', (string) $expenseLine->debit);

        // 1000.00 × 8.1 % = 81.00, owed and deducted in the same entry.
        $this->assertSame('81.00', (string) $inputVat->debit);
        $this->assertSame('81.00', (string) $acquisition->credit);
    }

    public function test_reverse_charge_does_not_touch_the_result(): void
    {
        $expense = $this->postExpense(ExpenseTaxTreatment::ReverseCharge->value);

        $expenseLines = $expense->journalEntry->lines
            ->where('account_id', $this->accountId('6530'));

        // Acquisition tax is a wash: it must not inflate the expense account.
        $this->assertEqualsWithDelta(1000.00, (float) $expenseLines->sum('debit'), 0.001);
    }

    public function test_standard_treatment_still_adds_vat_to_the_payment(): void
    {
        $expense = $this->postExpense(ExpenseTaxTreatment::Standard->value);

        $lines = $expense->journalEntry->lines;
        $this->assertCount(3, $lines);

        $bank = $lines->firstWhere('account_id', $this->accountId('1020'));
        $this->assertSame('1081.00', (string) $bank->credit);

        $this->assertNull($lines->firstWhere('account_id', $this->accountId('2202')));
    }

    public function test_import_tax_posts_the_net_expense_without_a_vat_line(): void
    {
        $expense = $this->postExpense(ExpenseTaxTreatment::ImportTax->value);

        $lines = $expense->journalEntry->lines;

        // Import VAT is assessed by customs, so nothing deductible can be derived
        // from the supplier invoice — the entry stays a plain net purchase.
        $this->assertCount(2, $lines);
        $this->assertSame('1000.00', (string) $lines->firstWhere('account_id', $this->accountId('1020'))->credit);
        $this->assertNull($lines->firstWhere('account_id', $this->accountId('1170')));
    }

    public function test_treatment_without_vat_posts_a_plain_expense(): void
    {
        $expense = $this->postExpense(ExpenseTaxTreatment::None->value);

        $lines = $expense->journalEntry->lines;
        $this->assertCount(2, $lines);
        $this->assertNull($lines->firstWhere('account_id', $this->accountId('1170')));
    }

    public function test_reverse_charge_is_recorded_as_owed_and_as_deductible(): void
    {
        $expense = $this->postExpense(ExpenseTaxTreatment::ReverseCharge->value);

        $entries = VatEntry::where('journal_entry_id', $expense->journal_entry_id)->get();

        $this->assertCount(2, $entries);
        $this->assertSame('81.00', (string) $entries->firstWhere('type', VatEntryType::Acquisition)->vat_amount);
        $this->assertSame('81.00', (string) $entries->firstWhere('type', VatEntryType::Input)->vat_amount);
    }

    public function test_vat_report_shows_acquisition_tax_separately_and_nets_to_zero(): void
    {
        $this->postExpense(ExpenseTaxTreatment::ReverseCharge->value);

        $report = app(VatReportService::class)->generateFresh(
            $this->org->id, '2026-01-01', '2026-12-31'
        );

        // Figure 380/381: the tax we owe on the foreign service.
        $this->assertSame('1000.00', $report['acquisition_tax_base']);
        $this->assertSame('81.00', $report['acquisition_tax']);

        // Figure 400: the same amount deducted again.
        $this->assertSame('81.00', $report['input_vat']);

        // Fully deductible acquisition tax must not change what is payable.
        $this->assertSame('0.00', $report['net_vat']);
    }
}
