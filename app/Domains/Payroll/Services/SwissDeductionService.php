<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Payroll\Models\DeductionRate;
use App\Support\Money;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Provides Swiss payroll deduction rates (AVS, AC, LPP, AANP, etc.)
 * and calculates employee/employer deduction amounts from gross salary.
 *
 * Falls back to built-in 2026 rate defaults when the organization
 * has not configured custom deduction rates.
 */
class SwissDeductionService
{
    /**
     * Default Swiss deduction rates (2026 estimates) used when
     * the organization has not configured custom rates.
     */
    private const DEFAULTS = [
        ['code' => 'avs_employee', 'name' => 'AVS/AI/APG (employee)', 'rate' => '5.3000', 'type' => 'employee'],
        ['code' => 'avs_employer', 'name' => 'AVS/AI/APG (employer)', 'rate' => '5.3000', 'type' => 'employer'],
        ['code' => 'ac_employee', 'name' => 'AC (employee)', 'rate' => '1.1000', 'type' => 'employee'],
        ['code' => 'ac_employer', 'name' => 'AC (employer)', 'rate' => '1.1000', 'type' => 'employer'],
        ['code' => 'aanp_employee', 'name' => 'AANP (employee)', 'rate' => '1.0000', 'type' => 'employee'],
        ['code' => 'lpp_employee', 'name' => 'LPP (employee)', 'rate' => '7.0000', 'type' => 'employee'],
        ['code' => 'lpp_employer', 'name' => 'LPP (employer)', 'rate' => '7.0000', 'type' => 'employer'],
    ];

    /**
     * Codes the built-in defaults provide, so callers can tell a deduction
     * apart from the other entries in the calculated array.
     *
     * @return string[]
     */
    public static function defaultCodes(): array
    {
        return array_column(self::DEFAULTS, 'code');
    }

    // ──────────────────────────────────────────────────────────────
    //  Calculation
    // ──────────────────────────────────────────────────────────────

    /**
     * The key under which the named breakdown is stored, alongside the plain
     * code => amount entries. Reserved: a deduction rate must not use it.
     */
    public const LINES_KEY = 'lines';

    /**
     * Calculate all deductions for a given gross salary.
     *
     * Besides the plain code => amount entries the result carries a `lines`
     * breakdown: every deduction with the name and type it was calculated
     * under. Screens and documents render that list instead of a hardcoded
     * selection, so a rate an organization added itself is no longer invisible.
     *
     * @param  Collection<int, DeductionRate>|null  $rates
     * @return array{avs_employee: string, avs_employer: string, ac_employee: string, ac_employer: string, aanp_employee: string, lpp_employee: string, lpp_employer: string, total_employee: string, total_employer: string, net_salary: string, lines: array<int, array{code: string, name: string, type: string, amount: string}>}
     */
    public function calculateDeductions(string $grossSalary, ?Collection $rates = null): array
    {
        if ($rates === null || $rates->isEmpty()) {
            if (app()->bound(LoggerInterface::class)) {
                app(LoggerInterface::class)->warning(
                    'SwissDeductionService: no custom deduction rates found — falling back to built-in defaults. '
                    .'Verify your organisation\'s deduction rates are up to date for the current fiscal year.',
                );
            }
        }

        $rateMap = $this->buildRateMap($rates);

        $deductions = [];
        $lines = [];
        $totalEmployee = '0.00';
        $totalEmployer = '0.00';

        foreach ($rateMap as $code => $rate) {
            // A rate is either a percentage of the gross or a fixed amount per
            // period — a pension contribution from a contract is the latter.
            $amount = $rate['amount'] !== null
                ? Money::normalize($rate['amount'])
                : Money::percentage($grossSalary, (string) $rate['rate']);
            $deductions[$code] = $amount;
            $lines[] = [
                'code' => (string) $code,
                'name' => (string) $rate['name'],
                'type' => (string) $rate['type'],
                'amount' => $amount,
            ];

            if ($rate['type'] === 'employee') {
                $totalEmployee = Money::add($totalEmployee, $amount);
            } else {
                $totalEmployer = Money::add($totalEmployer, $amount);
            }
        }

        $deductions[self::LINES_KEY] = $lines;
        $deductions['total_employee'] = $totalEmployee;
        $deductions['total_employer'] = $totalEmployer;
        $deductions['net_salary'] = Money::subtract($grossSalary, $totalEmployee);

        return $deductions;
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Build the rate map from custom rates or defaults.
     *
     * @param  Collection<int, DeductionRate>|null  $rates
     * @return array<string, array{code: string, name: string, rate: string|null, amount: string|null, type: string, account_code: string|null, expense_account_code: string|null}>
     */
    private function buildRateMap(?Collection $rates): array
    {
        if ($rates === null || $rates->isEmpty()) {
            return collect(self::DEFAULTS)
                ->keyBy('code')
                ->map(fn (array $rate): array => [
                    'code' => $rate['code'],
                    'name' => $rate['name'],
                    'rate' => $rate['rate'],
                    'amount' => null,
                    'type' => $rate['type'],
                    'account_code' => null,
                    'expense_account_code' => null,
                ])
                ->toArray();
        }

        // keyBy keeps the last match, so an employee-specific rate has to come
        // after the organization-wide one of the same code.
        return $rates
            ->where('is_active', true)
            ->sortBy(fn (DeductionRate $rate): int => $rate->employee_id === null ? 0 : 1)
            ->keyBy('code')
            ->map(fn (DeductionRate $rate) => [
                'code' => $rate->code,
                'name' => $rate->name,
                'rate' => $rate->rate,
                'amount' => $rate->amount,
                'type' => $rate->type,
                'account_code' => $rate->account_code,
                'expense_account_code' => $rate->expense_account_code,
            ])
            ->toArray();
    }
}
