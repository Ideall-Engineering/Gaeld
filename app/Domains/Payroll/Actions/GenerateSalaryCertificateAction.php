<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class GenerateSalaryCertificateAction
{
    /**
     * @return array{
     *     chiffres: array<int, array{chiffre: string, key: string, amount: string, emphasis?: string}>,
     *     period_from: CarbonImmutable,
     *     period_to: CarbonImmutable,
     *     employee: Employee,
     *     organization: Organization,
     *     year: int,
     *     months_covered: int,
     *     gross_salary: string,
     *     avs_employee: string,
     *     ac_employee: string,
     *     aanp_employee: string,
     *     lpp_employee: string,
     *     source_tax: string,
     *     reimbursements: string,
     *     net_salary: string,
     *     total_paid: string,
     *     employer_charges: string,
     * }
     */
    public function execute(Employee $employee, int $year): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Salary certificate year is outside the supported range.');
        }

        $employee->loadMissing('organization');

        $slips = SalarySlip::query()
            ->where('employee_id', $employee->id)
            ->where('organization_id', $employee->organization_id)
            ->where('period_year', $year)
            // A month booked outside the module counts as much as a posted one:
            // the certificate states what was paid, not what this module booked.
            ->where(fn ($query) => $query
                ->whereNotNull('posted_at')
                ->orWhere('booked_externally', true))
            ->orderBy('period_month')
            ->get();

        $grossSalary = $this->sumSlipValues($slips, 'gross_salary');
        $sourceTax = $this->sumDeduction($slips, 'source_tax');
        $reimbursements = $this->sumAdjustment($slips, 'reimbursement_amount');
        $employeeDeductions = $this->sumDeduction($slips, 'total_employee');
        $employeeDeductions = Money::add($employeeDeductions, $sourceTax);
        $netSalary = Money::subtract($grossSalary, $employeeDeductions);
        $totalPaid = Money::add($netSalary, $reimbursements);

        $flatExpenses = $this->sumFlatExpenses($slips);

        return [
            'chiffres' => $this->chiffres(
                grossSalary: $grossSalary,
                socialContributions: Money::add(
                    Money::add(
                        $this->sumDeduction($slips, 'avs_employee'),
                        $this->sumDeduction($slips, 'ac_employee'),
                    ),
                    $this->sumDeduction($slips, 'aanp_employee'),
                ),
                pension: $this->sumDeduction($slips, 'lpp_employee'),
                netSalary: $netSalary,
                sourceTax: $sourceTax,
                flatExpenses: $flatExpenses,
                actualExpenses: Money::subtract($reimbursements, $flatExpenses),
            ),
            'period_from' => $this->periodStart($employee, $year),
            'period_to' => $this->periodEnd($employee, $year),
            'employee' => $employee,
            'organization' => $employee->organization,
            'year' => $year,
            'months_covered' => $slips->pluck('period_month')->unique()->count(),
            'gross_salary' => $grossSalary,
            'avs_employee' => $this->sumDeduction($slips, 'avs_employee'),
            'ac_employee' => $this->sumDeduction($slips, 'ac_employee'),
            'aanp_employee' => $this->sumDeduction($slips, 'aanp_employee'),
            'lpp_employee' => $this->sumDeduction($slips, 'lpp_employee'),
            'source_tax' => $sourceTax,
            'reimbursements' => $reimbursements,
            'net_salary' => $netSalary,
            'total_paid' => $totalPaid,
            'employer_charges' => $this->sumDeduction($slips, 'total_employer'),
        ];
    }

    /**
     * The "from" date of the form's employment period.
     *
     * The year's first day, unless the employee joined during it.
     */
    private function periodStart(Employee $employee, int $year): CarbonImmutable
    {
        $yearStart = CarbonImmutable::create($year, 1, 1);
        $entry = CarbonImmutable::parse($employee->entry_date);

        return $entry->greaterThan($yearStart) ? $entry : $yearStart;
    }

    /**
     * The "to" date of the form's employment period.
     *
     * The year's last day, unless the employee left during it.
     */
    private function periodEnd(Employee $employee, int $year): CarbonImmutable
    {
        $yearEnd = CarbonImmutable::create($year, 12, 31);
        $exit = $employee->exit_date ? CarbonImmutable::parse($employee->exit_date) : null;

        return $exit !== null && $exit->lessThan($yearEnd) ? $exit : $yearEnd;
    }

    /**
     * The numbered boxes of the official Swiss salary certificate (Form 11).
     *
     * Only the boxes this payroll module can actually fill are listed. The
     * ones it cannot — fringe benefits (2.x), irregular payments (3), capital
     * payments (4), employee shares (5), board fees (6) — are deliberately
     * absent rather than printed as zero, because a printed zero is a claim
     * that nothing of the kind was paid, and this module would not know.
     *
     * @return array<int, array{chiffre: string, key: string, amount: string, emphasis?: string}>
     */
    private function chiffres(
        string $grossSalary,
        string $socialContributions,
        string $pension,
        string $netSalary,
        string $sourceTax,
        string $flatExpenses,
        string $actualExpenses,
    ): array {
        return [
            ['chiffre' => '1', 'key' => 'salary', 'amount' => $grossSalary],
            ['chiffre' => '8', 'key' => 'gross_total', 'amount' => $grossSalary, 'emphasis' => 'subtotal'],
            ['chiffre' => '9', 'key' => 'social_contributions', 'amount' => $socialContributions],
            ['chiffre' => '10.1', 'key' => 'pension_regular', 'amount' => $pension],
            ['chiffre' => '11', 'key' => 'net_salary', 'amount' => $netSalary, 'emphasis' => 'total'],
            ['chiffre' => '12', 'key' => 'source_tax', 'amount' => $sourceTax],
            ['chiffre' => '13.1.2', 'key' => 'expenses_actual_other', 'amount' => $actualExpenses],
            ['chiffre' => '13.2.3', 'key' => 'expenses_flat_other', 'amount' => $flatExpenses],
        ];
    }

    /**
     * The part of the year's reimbursements that came from the agreed monthly
     * flat sum, read from each slip's snapshot so a later contract change does
     * not rewrite a certificate for a closed year.
     *
     * Capped at what was actually reimbursed in the month: if a run entered a
     * smaller amount, that amount replaced the flat sum rather than adding to
     * it, and none of it was flat.
     *
     * @param  Collection<int, SalarySlip>  $slips
     */
    private function sumFlatExpenses(Collection $slips): string
    {
        return $slips->reduce(function (string $total, SalarySlip $slip): string {
            $allowance = Money::normalize((string) ($slip->employee_snapshot['expense_allowance'] ?? '0.00'));
            $reimbursed = Money::normalize((string) ($slip->adjustments['reimbursement_amount'] ?? '0.00'));

            return Money::add(
                $total,
                (float) $allowance <= (float) $reimbursed ? $allowance : $reimbursed,
            );
        }, Money::zero());
    }

    /**
     * @param  Collection<int, SalarySlip>  $slips
     */
    private function sumSlipValues(Collection $slips, string $attribute): string
    {
        return $slips->reduce(
            fn (string $total, SalarySlip $slip): string => Money::add($total, (string) $slip->{$attribute}),
            Money::zero(),
        );
    }

    /**
     * @param  Collection<int, SalarySlip>  $slips
     */
    private function sumDeduction(Collection $slips, string $key): string
    {
        return $slips->reduce(
            fn (string $total, SalarySlip $slip): string => Money::add(
                $total,
                (string) ($slip->deductions[$key]
                    ?? ($key === 'source_tax' ? $slip->getAttribute('source_tax_amount') : null)
                    ?? '0.00'),
            ),
            Money::zero(),
        );
    }

    /**
     * @param  Collection<int, SalarySlip>  $slips
     */
    private function sumAdjustment(Collection $slips, string $key): string
    {
        return $slips->reduce(
            fn (string $total, SalarySlip $slip): string => Money::add(
                $total,
                (string) ($slip->adjustments[$key] ?? $slip->deductions[$key] ?? '0.00'),
            ),
            Money::zero(),
        );
    }
}
