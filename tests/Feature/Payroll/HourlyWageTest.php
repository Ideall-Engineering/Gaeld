<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * An hourly wage is the hours worked at an agreed rate, plus holiday pay and
 * the share of a thirteenth salary as percentages the employment contract sets.
 * None of that is a monthly salary pro-rated over the days of a month, which is
 * all the module could calculate before.
 */
class HourlyWageTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['1020', AccountType::Asset], ['2270', AccountType::Liability],
            ['2271', AccountType::Liability], ['2272', AccountType::Liability],
            ['5000', AccountType::Expense], ['5700', AccountType::Expense],
            ['6530', AccountType::Expense],
        ] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->organization->id,
                'code' => $code,
                'name' => 'Konto '.$code,
                'type' => $type->value,
            ]);
        }
    }

    private function hourlyEmployee(array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'organization_id' => $this->organization->id,
            'salary_type' => 'hourly',
            'gross_salary' => '0.00',
            'hourly_rate' => '32.00',
            'vacation_compensation_rate' => '8.3300',
            'thirteenth_compensation_rate' => '8.3300',
            'entry_date' => '2026-01-01',
            'has_thirteenth_salary' => true,
            ...$attributes,
        ]);
    }

    // ──────────────────────────────────────────────────────────────

    public function test_the_gross_is_hours_times_rate_plus_both_percentages(): void
    {
        $slip = app(PayrollCalculator::class)->calculate($this->hourlyEmployee(), 9, 2026, hoursWorked: '80.00');

        // 80 × 32.00 = 2'560.00, plus 8.33 % twice.
        $this->assertSame('2560.00', $slip->adjustments['base_salary']);
        $this->assertSame('213.25', $slip->adjustments['vacation_compensation']);
        $this->assertSame('213.25', $slip->adjustments['thirteenth_compensation']);
        $this->assertSame('2986.50', $slip->gross_salary);
    }

    public function test_a_percentage_the_contract_does_not_grant_is_not_paid(): void
    {
        $employee = $this->hourlyEmployee([
            'vacation_compensation_rate' => null,
            'thirteenth_compensation_rate' => null,
        ]);

        $slip = app(PayrollCalculator::class)->calculate($employee, 9, 2026, hoursWorked: '80.00');

        $this->assertSame('2560.00', $slip->gross_salary);
    }

    public function test_deductions_are_taken_on_the_whole_gross(): void
    {
        DeductionRate::create([
            'organization_id' => $this->organization->id,
            'name' => 'AHV/IV/EO (AN)',
            'code' => 'avs_employee',
            'rate' => '5.3000',
            'type' => 'employee',
            'account_code' => '2270',
            'expense_account_code' => '5700',
            'is_active' => true,
        ]);

        $slip = app(PayrollCalculator::class)->calculate($this->hourlyEmployee(), 9, 2026, hoursWorked: '80.00');

        // Holiday pay and the thirteenth share are wages: AHV is owed on them.
        $this->assertSame(Money::percentage('2986.50', '5.3'), $slip->deductions['avs_employee']);
    }

    public function test_december_adds_no_thirteenth_month_on_top(): void
    {
        $slip = app(PayrollCalculator::class)->calculate($this->hourlyEmployee(), 12, 2026, hoursWorked: '80.00');

        // The share is already in every payout; a further month would pay twice.
        $this->assertSame('0.00', $slip->adjustments['thirteenth_salary']);
        $this->assertSame('2986.50', $slip->gross_salary);
    }

    public function test_unpaid_leave_days_do_not_apply(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PayrollCalculator::class)->calculate($this->hourlyEmployee(), 9, 2026, unpaidLeaveDays: 3, hoursWorked: '80.00');
    }

    public function test_an_hourly_wage_without_a_rate_is_refused(): void
    {
        $employee = $this->hourlyEmployee(['hourly_rate' => null]);

        $this->expectException(\InvalidArgumentException::class);

        app(PayrollCalculator::class)->calculate($employee, 9, 2026, hoursWorked: '80.00');
    }

    public function test_a_month_without_hours_produces_no_slip(): void
    {
        $employee = $this->hourlyEmployee();

        $slips = app(GeneratePayrollRunAction::class)->execute(
            $this->organization->id,
            9,
            2026,
            employeeIds: [$employee->id],
        );

        $this->assertCount(0, $slips);
        $this->assertSame(0, SalarySlip::count());
    }

    public function test_a_monthly_employee_is_unaffected_by_hours_being_available(): void
    {
        $employee = Employee::factory()->create([
            'organization_id' => $this->organization->id,
            'gross_salary' => '5000.00',
            'entry_date' => '2026-01-01',
        ]);

        $slips = app(GeneratePayrollRunAction::class)->execute(
            $this->organization->id,
            9,
            2026,
            employeeIds: [$employee->id],
        );

        $this->assertSame('5000.00', $slips->sole()->gross_salary);
        $this->assertSame('0.00', $slips->sole()->adjustments['hours_worked']);
    }

    public function test_a_run_posts_an_hourly_month_the_hours_were_given_for(): void
    {
        $employee = $this->hourlyEmployee();

        $this->actAsOrg()->postJson('/payroll/run', [
            'month' => 9,
            'year' => 2026,
            'employee_ids' => [$employee->id],
            'adjustments' => [
                ['employee_id' => $employee->id, 'hours_worked' => '80.00'],
            ],
        ])->assertOk();

        $slip = SalarySlip::sole();
        $this->assertSame('2986.50', $slip->gross_salary);

        $posted = app(PostPayrollAction::class)->execute($slip);
        $this->assertNotNull($posted->journal_entry_id);
    }
}
