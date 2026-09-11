<?php

namespace Tests\Feature\Payroll;

use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * An employee can have a monthly flat expense sum agreed in their contract.
 *
 * It behaves exactly like a reimbursement entered for the run: added after the
 * social deductions, so it is not AHV-liable. What it adds is that nobody has
 * to retype it every month.
 */
class ExpenseAllowanceTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganization();
    }

    #[Test]
    public function the_agreed_flat_sum_is_reimbursed_without_being_entered_again(): void
    {
        $employee = $this->employee(['expense_allowance' => '250.00']);

        $slip = app(GeneratePayrollRunAction::class)
            ->preview($this->org->id, 3, 2026, [$employee->id])
            ->first();

        $this->assertSame('250.00', $slip->adjustments['reimbursement_amount']);
    }

    #[Test]
    public function an_amount_entered_for_the_run_replaces_the_flat_sum(): void
    {
        // The run sheet states what the month's expenses actually were; the
        // flat sum is an estimate it supersedes rather than adds to.
        $employee = $this->employee(['expense_allowance' => '250.00']);

        $slip = app(GeneratePayrollRunAction::class)
            ->preview($this->org->id, 3, 2026, [$employee->id], [
                ['employee_id' => $employee->id, 'reimbursement_amount' => '80.00'],
            ])
            ->first();

        $this->assertSame('80.00', $slip->adjustments['reimbursement_amount']);
    }

    #[Test]
    public function an_employee_without_a_flat_sum_is_unaffected(): void
    {
        $employee = $this->employee();

        $slip = app(GeneratePayrollRunAction::class)
            ->preview($this->org->id, 3, 2026, [$employee->id])
            ->first();

        $this->assertSame('0.00', $slip->adjustments['reimbursement_amount']);
    }

    #[Test]
    public function the_flat_sum_is_added_after_the_social_deductions(): void
    {
        $withAllowance = $this->employee(['expense_allowance' => '250.00']);
        $without = $this->employee();

        $slips = app(GeneratePayrollRunAction::class)
            ->preview($this->org->id, 3, 2026, [$withAllowance->id, $without->id])
            ->keyBy('employee_id');

        // Same gross salary, so the same contributions — the allowance must not
        // enlarge the AHV-liable base.
        $this->assertSame(
            $slips[$without->id]->gross_salary,
            $slips[$withAllowance->id]->gross_salary,
        );
        $this->assertSame(
            $slips[$without->id]->deductions['total_employee'],
            $slips[$withAllowance->id]->deductions['total_employee'],
        );

        $difference = (float) $slips[$withAllowance->id]->net_salary
            - (float) $slips[$without->id]->net_salary;
        $this->assertEqualsWithDelta(250.00, $difference, 0.001);
    }

    #[Test]
    public function the_slip_records_the_flat_sum_that_applied_when_it_was_generated(): void
    {
        // Otherwise raising the agreed sum would silently reinterpret slips
        // that were already posted.
        $employee = $this->employee(['expense_allowance' => '250.00']);

        $slip = app(GeneratePayrollRunAction::class)
            ->preview($this->org->id, 3, 2026, [$employee->id])
            ->first();

        $this->assertSame('250.00', $slip->employee_snapshot['expense_allowance']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ], $attributes));
    }
}
