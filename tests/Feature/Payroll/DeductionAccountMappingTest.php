<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Exceptions\UnmappedDeductionException;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payroll module has to book the contributions a Swiss employer actually
 * pays — not only AHV, ALV and BVG. Each rate names its own accounts.
 */
class DeductionAccountMappingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Employee $employeeA;

    private Employee $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        $accounts = [
            ['1020', AccountType::Asset], ['1091', AccountType::Asset],
            ['2270', AccountType::Liability], ['2271', AccountType::Liability],
            ['2272', AccountType::Liability], ['2273', AccountType::Liability],
            ['5000', AccountType::Expense], ['5700', AccountType::Expense],
            ['5720', AccountType::Expense], ['5730', AccountType::Expense],
            ['5740', AccountType::Expense], ['5750', AccountType::Expense],
            ['6530', AccountType::Expense],
        ];

        foreach ($accounts as [$code, $type]) {
            Account::create([
                'organization_id' => $this->organization->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type->value,
            ]);
        }

        $this->employeeA = Employee::factory()->create([
            'organization_id' => $this->organization->id,
            'gross_salary' => '1107.69',
        ]);
        $this->employeeB = Employee::factory()->create([
            'organization_id' => $this->organization->id,
            'gross_salary' => '3692.31',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function rate(array $attributes): DeductionRate
    {
        return DeductionRate::create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * The full Swiss set: five contributions beyond what the built-in defaults
     * know, each on its own account.
     */
    private function seedFullSwissRates(): void
    {
        $this->rate(['name' => 'AHV AN', 'code' => 'avs_employee', 'rate' => '5.3000', 'type' => 'employee', 'account_code' => '2270']);
        $this->rate(['name' => 'AHV AG', 'code' => 'avs_employer', 'rate' => '5.3000', 'type' => 'employer', 'account_code' => '2270', 'expense_account_code' => '5700']);
        $this->rate(['name' => 'ALV AN', 'code' => 'ac_employee', 'rate' => '1.1000', 'type' => 'employee', 'account_code' => '2270']);
        $this->rate(['name' => 'ALV AG', 'code' => 'ac_employer', 'rate' => '1.1000', 'type' => 'employer', 'account_code' => '2270', 'expense_account_code' => '5700']);
        $this->rate(['name' => 'Verwaltungskosten', 'code' => 'admin_employer', 'rate' => '0.5000', 'type' => 'employer', 'account_code' => '2270', 'expense_account_code' => '5700']);
        $this->rate(['name' => 'FAK', 'code' => 'fak_employer', 'rate' => '1.4500', 'type' => 'employer', 'account_code' => '2270', 'expense_account_code' => '5720']);
        $this->rate(['name' => 'NBU AN', 'code' => 'aanp_employee', 'rate' => '1.2410', 'type' => 'employee', 'account_code' => '2272']);
        $this->rate(['name' => 'UVG BU AG', 'code' => 'uvg_employer', 'rate' => '0.0700', 'type' => 'employer', 'account_code' => '2272', 'expense_account_code' => '5740']);
        $this->rate(['name' => 'KTG AN', 'code' => 'ktg_employee', 'rate' => '0.7785', 'type' => 'employee', 'account_code' => '2272']);
        $this->rate(['name' => 'KTG AG', 'code' => 'ktg_employer', 'rate' => '0.7785', 'type' => 'employer', 'account_code' => '2272', 'expense_account_code' => '5750']);
    }

    private function postFor(Employee $employee): SalarySlip
    {
        $slip = app(PayrollCalculator::class)->calculate($employee, 5, 2026);
        $slip->save();

        return app(PostPayrollAction::class)->execute($slip);
    }

    private function assertBalanced(SalarySlip $slip): void
    {
        $lines = $slip->journalEntry->lines;
        $debit = $this->total($lines->pluck('debit')->all());
        $credit = $this->total($lines->pluck('credit')->all());

        $this->assertSame(0, Money::compare($debit, $credit), "Entry is unbalanced: {$debit} vs {$credit}");
    }

    /** @param array<int, mixed> $values */
    private function total(array $values): string
    {
        return array_reduce(
            $values,
            static fn (string $carry, mixed $value): string => Money::add($carry, (string) $value),
            '0.00',
        );
    }

    // ──────────────────────────────────────────────────────────────

    public function test_the_full_swiss_contribution_set_produces_a_balanced_entry(): void
    {
        $this->seedFullSwissRates();

        $slip = $this->postFor($this->employeeA);

        $this->assertBalanced($slip);
    }

    public function test_each_employer_contribution_lands_on_its_own_expense_account(): void
    {
        $this->seedFullSwissRates();

        $slip = $this->postFor($this->employeeA);
        $byCode = $slip->journalEntry->lines->load('account')
            ->groupBy(fn ($line) => $line->account->code);

        // 1'107.69 × 1.45 % FAK, × 0.07 % UVG, × 0.7785 % KTG.
        $this->assertSame('16.06', (string) $byCode['5720']->sum(fn ($l) => (float) $l->debit));
        $this->assertSame('0.78', (string) $byCode['5740']->sum(fn ($l) => (float) $l->debit));
        $this->assertSame('8.62', (string) $byCode['5750']->sum(fn ($l) => (float) $l->debit));
    }

    public function test_each_liability_keeps_its_own_line_and_they_sum_to_the_account_total(): void
    {
        $this->seedFullSwissRates();

        $slip = $this->postFor($this->employeeA);
        $lines = $slip->journalEntry->lines->load('account')
            ->groupBy(fn ($line) => $line->account->code);

        // 2270 carries AHV and ALV from both sides plus administration and FAK.
        // One line each: a reader can check a contribution in the journal
        // instead of only on the salary slip.
        $expected2270 = $this->total(['58.71', '58.71', '12.18', '12.18', '5.54', '16.06']);
        $this->assertCount(6, $lines['2270']);
        $this->assertSame($expected2270, $this->total($lines['2270']->pluck('credit')->all()));
    }

    public function test_a_liability_line_is_named_after_its_contribution(): void
    {
        $this->seedFullSwissRates();

        $slip = $this->postFor($this->employeeA);
        $descriptions = $slip->journalEntry->lines->pluck('description')->all();

        $this->assertContains('FAK', $descriptions);
        $this->assertNotContains('Social security contributions', $descriptions);
    }

    public function test_a_fixed_amount_is_used_instead_of_a_percentage(): void
    {
        $this->seedFullSwissRates();
        $this->rate([
            'name' => 'BVG AN', 'code' => 'lpp_employee', 'amount' => '19.35',
            'type' => 'employee', 'account_code' => '2271',
        ]);

        $slip = $this->postFor($this->employeeA);
        $lines = $slip->journalEntry->lines->load('account')->keyBy(fn ($l) => $l->account->code);

        $this->assertSame('19.35', (string) $lines['2271']->credit);
        $this->assertBalanced($slip);
    }

    public function test_an_employee_specific_rate_overrides_the_organization_wide_one(): void
    {
        $this->seedFullSwissRates();
        $this->rate(['name' => 'BVG AN', 'code' => 'lpp_employee', 'amount' => '19.35', 'type' => 'employee', 'account_code' => '2271']);
        $this->rate([
            'name' => 'BVG AN Mitarbeiter B', 'code' => 'lpp_employee', 'amount' => '85.55',
            'type' => 'employee', 'account_code' => '2271', 'employee_id' => $this->employeeB->id,
        ]);

        $linesA = $this->postFor($this->employeeA)->journalEntry->lines->load('account')->keyBy(fn ($l) => $l->account->code);
        $linesB = $this->postFor($this->employeeB)->journalEntry->lines->load('account')->keyBy(fn ($l) => $l->account->code);

        $this->assertSame('19.35', (string) $linesA['2271']->credit);
        $this->assertSame('85.55', (string) $linesB['2271']->credit);
    }

    public function test_without_configured_accounts_the_built_in_behaviour_is_unchanged(): void
    {
        // No DeductionRate rows at all — the service falls back to its defaults
        // and the action to the hardcoded account mapping.
        $slip = $this->postFor($this->employeeA);
        $lines = $slip->journalEntry->lines->load('account')->keyBy(fn ($l) => $l->account->code);

        $this->assertBalanced($slip);
        $this->assertTrue($lines->has('2270'));
        $this->assertTrue($lines->has('2271'));
        $this->assertTrue($lines->has('2272'));
        $this->assertTrue($lines->has('5700'));
    }

    public function test_the_gross_salary_follows_the_configured_account(): void
    {
        Account::create([
            'organization_id' => $this->organization->id,
            'code' => '5600', 'name' => 'Löhne', 'type' => AccountType::Expense->value,
        ]);
        $this->organization->update(['payroll_salary_account_code' => '5600']);
        $this->seedFullSwissRates();

        $slip = $this->postFor($this->employeeA);
        $codes = $slip->journalEntry->lines->load('account')->pluck('account.code');

        $this->assertTrue($codes->contains('5600'));
        $this->assertFalse($codes->contains('5000'));
        $this->assertBalanced($slip);
    }

    public function test_a_reimbursement_follows_the_configured_account(): void
    {
        Account::create([
            'organization_id' => $this->organization->id,
            'code' => '5620', 'name' => 'Spesen', 'type' => AccountType::Expense->value,
        ]);
        $this->organization->update(['payroll_reimbursement_account_code' => '5620']);
        $this->seedFullSwissRates();

        $slip = app(PayrollCalculator::class)->calculate($this->employeeA, 5, 2026, reimbursementAmount: '50.00');
        $slip->save();
        $posted = app(PostPayrollAction::class)->execute($slip);

        $lines = $posted->journalEntry->lines->load('account')->keyBy(fn ($l) => $l->account->code);

        $this->assertSame('50.00', (string) $lines['5620']->debit);
        $this->assertFalse($lines->has('6530'));
        $this->assertBalanced($posted);
    }

    public function test_a_deduction_without_an_account_is_refused_at_posting(): void
    {
        $this->seedFullSwissRates();
        $this->rate([
            'name' => 'Weiterbildungsfonds', 'code' => 'training_employer',
            'rate' => '0.2000', 'type' => 'employer', 'expense_account_code' => '5700',
        ]);

        $this->expectException(UnmappedDeductionException::class);

        $this->postFor($this->employeeA);
    }
}
