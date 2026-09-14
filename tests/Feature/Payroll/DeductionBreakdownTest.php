<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Swiss employer pays more than AHV, ALV and BVG. The screens and the salary
 * slip used to name four contributions and total all of them, so the rows did
 * not add up to the figure printed underneath, and a rate an organization had
 * configured itself was nowhere to be seen.
 */
class DeductionBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['locale' => 'de']);

        foreach ([
            ['1020', AccountType::Asset], ['2270', AccountType::Liability],
            ['2271', AccountType::Liability], ['2272', AccountType::Liability],
            ['5000', AccountType::Expense], ['5700', AccountType::Expense],
            ['5750', AccountType::Expense], ['6530', AccountType::Expense],
        ] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->organization->id,
                'code' => $code,
                'name' => 'Konto '.$code,
                'type' => $type->value,
            ]);
        }

        $this->employee = Employee::factory()->create([
            'organization_id' => $this->organization->id,
            'gross_salary' => '5000.00',
        ]);

        foreach ([
            ['AHV/IV/EO (AN)', 'avs_employee', '5.3000', 'employee', '2270', '5700'],
            ['AHV/IV/EO (AG)', 'avs_employer', '5.3000', 'employer', '2270', '5700'],
            ['KTG Krankentaggeld (AN)', 'ktg_employee', '0.7785', 'employee', '2272', '5750'],
            ['KTG Krankentaggeld (AG)', 'ktg_employer', '0.7785', 'employer', '2272', '5750'],
        ] as [$name, $code, $rate, $type, $account, $expenseAccount]) {
            DeductionRate::create([
                'organization_id' => $this->organization->id,
                'name' => $name,
                'code' => $code,
                'rate' => $rate,
                'type' => $type,
                'account_code' => $account,
                'expense_account_code' => $expenseAccount,
                'is_active' => true,
            ]);
        }
    }

    private function slip(): SalarySlip
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee, 9, 2026);
        $slip->save();

        return $slip;
    }

    // ──────────────────────────────────────────────────────────────

    public function test_the_calculation_names_every_deduction_it_applied(): void
    {
        $lines = collect($this->slip()->deductions['lines']);

        $this->assertSame(
            ['avs_employee', 'avs_employer', 'ktg_employee', 'ktg_employer'],
            $lines->pluck('code')->sort()->values()->all(),
        );
        $this->assertSame(
            'KTG Krankentaggeld (AN)',
            $lines->firstWhere('code', 'ktg_employee')['name'],
        );
        $this->assertSame('employer', $lines->firstWhere('code', 'ktg_employer')['type']);
    }

    public function test_the_salary_slip_lists_every_deduction_behind_its_total(): void
    {
        $slip = $this->slip();
        $deductions = $slip->deductions;

        $html = view('exports.salary-slip', [
            'slip' => $slip,
            'employeeData' => $slip->employeeDocumentData(),
            'organization' => $this->organization,
        ])->render();

        // The contribution that used to be counted but never shown.
        $this->assertStringContainsString('KTG Krankentaggeld (AN)', $html);
        $this->assertStringContainsString('KTG Krankentaggeld (AG)', $html);

        // What is listed and what is totalled are the same set now.
        $listedEmployee = Money::add($deductions['avs_employee'], $deductions['ktg_employee']);
        $this->assertSame($listedEmployee, $deductions['total_employee']);
        $this->assertStringContainsString(number_format((float) $listedEmployee, 2, '.', "'"), $html);
    }

    public function test_the_journal_entry_is_written_in_the_organizations_language(): void
    {
        $this->app->setLocale('en');

        $slip = app(PostPayrollAction::class)->execute($this->slip());
        $entry = $slip->journalEntry;
        $descriptions = $entry->lines->pluck('description')->all();

        $this->assertStringContainsString('Lohn', (string) $entry->description);
        $this->assertContains('Bruttolohn: '.$this->employee->fullName(), $descriptions);
        $this->assertContains('Nettolohn ausbezahlt: '.$this->employee->fullName(), $descriptions);
        $this->assertContains('KTG Krankentaggeld (AG)', $descriptions);
    }
}
