<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Payroll\Contracts\SourceTaxServiceInterface;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Calculates employee salary slips including gross-to-net computation,
 * Swiss social deductions, and pro-rata handling for partial months.
 */
class PayrollCalculator
{
    public function __construct(
        private SwissDeductionService $deductionService,
        private SourceTaxServiceInterface $sourceTax,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Calculation
    // ──────────────────────────────────────────────────────────────

    /**
     * Calculate salary for a given employee and period.
     * Handles pro-rata for partial months (entry/exit mid-month).
     */
    public function calculate(
        Employee $employee,
        int $month,
        int $year,
        int $unpaidLeaveDays = 0,
        string $reimbursementAmount = '0.00',
        string $hoursWorked = '0.00',
    ): SalarySlip {
        $period = Carbon::create($year, $month, 1);
        $isHourly = $employee->isHourly();

        if ($unpaidLeaveDays < 0 || $unpaidLeaveDays > $period->daysInMonth) {
            throw new \InvalidArgumentException('Unpaid leave days must be within the selected calendar month.');
        }

        if ($isHourly && $unpaidLeaveDays > 0) {
            // The hours already say what was worked; deducting days on top of
            // them would take the same absence off twice.
            throw new \InvalidArgumentException('Unpaid leave days do not apply to an hourly wage.');
        }

        if (! is_numeric($reimbursementAmount) || (float) $reimbursementAmount < 0) {
            throw new \InvalidArgumentException('Reimbursement amount must be a non-negative number.');
        }

        $reimbursementAmount = Money::normalize($reimbursementAmount);

        if ($isHourly) {
            [$baseSalary, $vacationCompensation, $thirteenthCompensation] = $this->hourlyComponents($employee, $hoursWorked);
            $unpaidLeaveAmount = '0.00';
            $thirteenthSalary = '0.00';
            $grossSalary = Money::add(Money::add($baseSalary, $vacationCompensation), $thirteenthCompensation);
        } else {
            $baseSalary = $this->proRataGross($employee, $month, $year);
            $vacationCompensation = '0.00';
            $thirteenthCompensation = '0.00';
            $unpaidLeaveAmount = $unpaidLeaveDays > 0
                ? Money::divideRounded(
                    Money::multiply($baseSalary, (string) $unpaidLeaveDays),
                    (string) $period->daysInMonth,
                )
                : '0.00';
            $thirteenthSalary = $employee->has_thirteenth_salary && $month === 12
                ? $baseSalary
                : '0.00';
            $grossSalary = Money::subtract(
                Money::add($baseSalary, $thirteenthSalary),
                $unpaidLeaveAmount,
            );
        }

        $rates = DeductionRate::where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('employee_id')
                ->orWhere('employee_id', $employee->id))
            ->get();

        $deductions = $this->deductionService->calculateDeductions($grossSalary, $rates);
        $deductions['base_salary'] = $baseSalary;
        $deductions['thirteenth_salary'] = $thirteenthSalary;
        $deductions['unpaid_leave_days'] = $unpaidLeaveDays;
        $deductions['unpaid_leave_amount'] = $unpaidLeaveAmount;
        $deductions['reimbursement_amount'] = $reimbursementAmount;
        $deductions['hours_worked'] = $isHourly ? Money::normalize($hoursWorked) : '0.00';
        $deductions['vacation_compensation'] = $vacationCompensation;
        $deductions['thirteenth_compensation'] = $thirteenthCompensation;
        $deductions['net_salary'] = Money::add($deductions['net_salary'], $reimbursementAmount);

        $slip = new SalarySlip([
            'employee_id' => $employee->id,
            'organization_id' => $employee->organization_id,
            'period_month' => $month,
            'period_year' => $year,
            'gross_salary' => $grossSalary,
            'net_salary' => $deductions['net_salary'],
            'deductions' => $deductions,
            'adjustments' => [
                'base_salary' => $baseSalary,
                'thirteenth_salary' => $thirteenthSalary,
                'unpaid_leave_days' => $unpaidLeaveDays,
                'unpaid_leave_amount' => $unpaidLeaveAmount,
                'reimbursement_amount' => $reimbursementAmount,
                'salary_type' => $employee->salary_type,
                'hours_worked' => $isHourly ? Money::normalize($hoursWorked) : '0.00',
                'hourly_rate' => $isHourly ? (string) $employee->hourly_rate : '0.00',
                'vacation_compensation' => $vacationCompensation,
                'thirteenth_compensation' => $thirteenthCompensation,
            ],
            'employee_snapshot' => [
                'first_name' => (string) $employee->first_name,
                'last_name' => (string) $employee->last_name,
                'email' => $employee->email,
                // Kept so a later change to the employee's agreed flat expense
                // sum cannot silently reinterpret slips already posted.
                'expense_allowance' => $employee->expense_allowance,
                'ahv_number' => $employee->ahv_number
                    ? Crypt::encryptString($employee->ahv_number)
                    : null,
                'ahv_number_encrypted' => true,
            ],
        ]);

        $this->sourceTax->applyToSlip($slip, $employee);
        $sourceTaxAmount = Money::normalize((string) ($slip->source_tax_amount ?? '0.00'));
        $deductions['source_tax'] = $sourceTaxAmount;
        $deductions['net_salary'] = Money::subtract($deductions['net_salary'], $sourceTaxAmount);

        $slip->forceFill([
            'net_salary' => $deductions['net_salary'],
            'deductions' => $deductions,
        ]);

        return $slip;
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * The three parts an hourly wage is made of.
     *
     * The hours worked at the agreed rate, plus holiday pay and the share of a
     * thirteenth salary as percentages of that amount. Both percentages come
     * from the employee record rather than a built-in figure: how much of each
     * is owed is a matter of the employment contract, and a wrong default would
     * be paid out month after month before anyone noticed.
     *
     * Both are calculated on the hours worked, not on each other.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function hourlyComponents(Employee $employee, string $hoursWorked): array
    {
        if (! is_numeric($hoursWorked) || (float) $hoursWorked < 0) {
            throw new \InvalidArgumentException('Hours worked must be a non-negative number.');
        }

        if ($employee->hourly_rate === null) {
            throw new \InvalidArgumentException('An hourly wage needs an hourly rate on the employee record.');
        }

        $baseSalary = Money::round(Money::multiply((string) $employee->hourly_rate, $hoursWorked));

        return [
            $baseSalary,
            $employee->vacation_compensation_rate === null
                ? '0.00'
                : Money::percentage($baseSalary, (string) $employee->vacation_compensation_rate),
            $employee->thirteenth_compensation_rate === null
                ? '0.00'
                : Money::percentage($baseSalary, (string) $employee->thirteenth_compensation_rate),
        ];
    }

    /**
     * Calculate pro-rata gross salary for partial months.
     */
    private function proRataGross(Employee $employee, int $month, int $year): string
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth()->startOfDay();
        $totalDays = $periodStart->daysInMonth;

        $effectiveStart = $periodStart->copy();
        $effectiveEnd = $periodEnd->copy();

        if ($employee->entry_date->greaterThan($periodEnd)
            || ($employee->exit_date && $employee->exit_date->lessThan($periodStart))) {
            return Money::zero();
        }

        // Employee started mid-month
        if ($employee->entry_date->greaterThan($periodStart) && $employee->entry_date->lessThanOrEqualTo($periodEnd)) {
            $effectiveStart = $employee->entry_date->copy();
        }

        // Employee exited mid-month
        if ($employee->exit_date && $employee->exit_date->greaterThanOrEqualTo($periodStart) && $employee->exit_date->lessThan($periodEnd)) {
            $effectiveEnd = $employee->exit_date->copy();
        }

        $workedDays = $effectiveStart->diffInDays($effectiveEnd) + 1;

        if ($workedDays >= $totalDays) {
            return $employee->gross_salary;
        }

        // Pro-rata: gross * workedDays / totalDays
        return Money::divideRounded(
            Money::multiply($employee->gross_salary, (string) $workedDays),
            (string) $totalDays,
        );
    }
}
