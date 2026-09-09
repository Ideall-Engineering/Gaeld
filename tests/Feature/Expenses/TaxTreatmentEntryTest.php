<?php

namespace Tests\Feature\Expenses;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Expenses\Actions\ApproveExpenseAction;
use App\Domains\Expenses\Actions\PostExpenseAction;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Expenses\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The path from a person filling in the form to the acquisition-tax entry.
 *
 * AcquisitionTaxPostingTest proves the posting is right when the treatment is
 * set. This proves the treatment can be set at all — the gap that made the
 * whole feature unreachable from the outside.
 */
class TaxTreatmentEntryTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['1020', AccountType::Asset],
            ['1170', AccountType::Asset],
            ['2202', AccountType::Liability],
            ['3900', AccountType::Revenue],
            ['6530', AccountType::Expense],
        ] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->org->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type->value,
            ]);
        }

        $this->vatRate = VatRate::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'Software and Subscriptions',
            'description' => 'Anthropic API',
            'amount' => 1000.00,
            'vat_rate_id' => $this->vatRate->id,
            'date' => '2026-01-15',
            'vendor' => 'Anthropic PBC',
        ], $overrides);
    }

    public function test_the_create_form_offers_the_treatments(): void
    {
        $this->actAsOrg()->get('/expenses/create')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->has('taxTreatments', 4));
    }

    public function test_an_expense_can_be_created_with_acquisition_tax(): void
    {
        $this->actAsOrg()
            ->post('/expenses', $this->payload([
                'tax_treatment' => ExpenseTaxTreatment::ReverseCharge->value,
            ]))
            ->assertRedirect();

        $this->assertSame(
            ExpenseTaxTreatment::ReverseCharge,
            Expense::firstOrFail()->tax_treatment,
        );
    }

    public function test_an_expense_created_without_a_treatment_stays_standard(): void
    {
        $this->actAsOrg()->post('/expenses', $this->payload())->assertRedirect();

        $this->assertSame(ExpenseTaxTreatment::Standard, Expense::firstOrFail()->tax_treatment);
    }

    public function test_an_invented_treatment_is_rejected(): void
    {
        $this->actAsOrg()
            ->post('/expenses', $this->payload(['tax_treatment' => 'wunschdenken']))
            ->assertSessionHasErrors('tax_treatment');

        $this->assertSame(0, Expense::count());
    }

    public function test_the_treatment_can_be_corrected_afterwards(): void
    {
        $this->actAsOrg()->post('/expenses', $this->payload())->assertRedirect();
        $expense = Expense::firstOrFail();

        $this->actAsOrg()
            ->put("/expenses/{$expense->id}", $this->payload([
                'tax_treatment' => ExpenseTaxTreatment::ImportTax->value,
            ]))
            ->assertRedirect();

        $this->assertSame(ExpenseTaxTreatment::ImportTax, $expense->fresh()->tax_treatment);
    }

    /**
     * The point of the whole exercise: a treatment chosen in the form has to
     * survive all the way into the journal entry.
     */
    public function test_a_treatment_chosen_in_the_form_reaches_the_ledger(): void
    {
        $this->actAsOrg()
            ->post('/expenses', $this->payload([
                'tax_treatment' => ExpenseTaxTreatment::ReverseCharge->value,
            ]))
            ->assertRedirect();

        $expense = app(PostExpenseAction::class)->execute(
            (new ApproveExpenseAction)->execute(Expense::firstOrFail()),
            '6530',
        );

        $lines = $expense->journalEntry->lines;
        $accountId = fn (string $code) => (int) Account::where('code', $code)
            ->where('organization_id', $this->org->id)->value('id');

        $this->assertCount(4, $lines);
        $this->assertSame('1000.00', (string) $lines->firstWhere('account_id', $accountId('1020'))->credit);
        $this->assertSame('81.00', (string) $lines->firstWhere('account_id', $accountId('1170'))->debit);
        $this->assertSame('81.00', (string) $lines->firstWhere('account_id', $accountId('2202'))->credit);
    }

    public function test_the_detail_page_shows_the_treatment(): void
    {
        $this->actAsOrg()->post('/expenses', $this->payload([
            'tax_treatment' => ExpenseTaxTreatment::ImportTax->value,
        ]))->assertRedirect();

        $expense = Expense::firstOrFail();

        $this->actAsOrg()->get("/expenses/{$expense->id}")
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('expense.tax_treatment', 'import_tax'));
    }
}
