<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Contracts\SourceTaxServiceInterface;
use App\Domains\Payroll\Exceptions\UnmappedDeductionException;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\SwissDeductionService;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Posts a salary slip to the accounting ledger (gross salary, deductions, net pay).
 */
class PostPayrollAction
{
    /**
     * Where the built-in Swiss deductions land when a rate names no account.
     * Keeps an installation that never configured accounts behaving exactly as
     * it did before.
     *
     * @var array<string, string>
     */
    private const FALLBACK_LIABILITY_ACCOUNTS = [
        'avs_employee' => AccountCode::AVS_PAYABLE,
        'avs_employer' => AccountCode::AVS_PAYABLE,
        'aanp_employee' => AccountCode::AVS_PAYABLE,
        'ac_employee' => AccountCode::AC_PAYABLE,
        'ac_employer' => AccountCode::AC_PAYABLE,
        'lpp_employee' => AccountCode::LPP_PAYABLE,
        'lpp_employer' => AccountCode::LPP_PAYABLE,
    ];

    public function __construct(
        private LedgerService $ledger,
        private LedgerQueryService $ledgerQuery,
        private SendSalarySlipEmailAction $sendEmail,
        private SourceTaxServiceInterface $sourceTax,
    ) {}

    public function execute(SalarySlip $slip): SalarySlip
    {
        $this->ensureSourceTaxApplied($slip);

        $deductions = $slip->deductions;
        $orgId = $slip->organization_id;

        $employee = $slip->employee;
        $description = "Salary {$employee->fullName()} — {$slip->period_month}/{$slip->period_year}";

        // Explicitly, not via $employee->organization: lazy loading is off
        // installation-wide and an unloaded relation throws.
        $organization = Organization::query()->whereKey($orgId)->first();
        $salaryAccount = $this->ledgerQuery->resolveAccount(
            $orgId,
            $organization?->payroll_salary_account_code === null
                ? AccountCode::SALARIES
                : $organization->payroll_salary_account_code,
        );
        $bankAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::BANK_CASH);

        $sourceTaxAmount = Money::normalize((string) ($deductions['source_tax'] ?? $slip->source_tax_amount ?? '0.00'));

        [$liabilities, $employerCosts] = $this->groupDeductions($slip, $deductions);

        $lines = [];

        // Debit: Gross salary
        $lines[] = new JournalLineData(
            accountId: (string) $salaryAccount->id,
            debit: $slip->gross_salary,
            credit: '0',
            description: "Gross salary: {$employee->fullName()}",
        );

        // Debit: Employer contributions, one line per expense account so a
        // chart that separates AHV, FAK, BVG, UVG and KTG stays readable.
        foreach ($employerCosts as $accountCode => $amount) {
            $account = $this->ledgerQuery->resolveAccount($orgId, (string) $accountCode);
            $lines[] = new JournalLineData(
                accountId: (string) $account->id,
                debit: $amount,
                credit: '0',
                description: "Employer social charges: {$employee->fullName()}",
            );
        }

        $reimbursementAmount = (string) ($deductions['reimbursement_amount'] ?? '0.00');
        if (Money::isPositive($reimbursementAmount)) {
            $reimbursementAccount = $this->ledgerQuery->resolveAccount(
                $orgId,
                $organization?->payroll_reimbursement_account_code === null
                    ? AccountCode::GENERAL_EXPENSE
                    : $organization->payroll_reimbursement_account_code,
            );
            $lines[] = new JournalLineData(
                accountId: (string) $reimbursementAccount->id,
                debit: $reimbursementAmount,
                credit: '0',
                description: "Expense reimbursement: {$employee->fullName()}",
            );
        }

        // Credit: Bank (net salary)
        $lines[] = new JournalLineData(
            accountId: (string) $bankAccount->id,
            debit: '0',
            credit: $slip->net_salary,
            description: "Net salary paid: {$employee->fullName()}",
        );

        // Credit: one liability line per account.
        foreach ($liabilities as $accountCode => $amount) {
            $account = $this->ledgerQuery->resolveAccount($orgId, (string) $accountCode);
            $lines[] = new JournalLineData(
                accountId: (string) $account->id,
                debit: '0',
                credit: $amount,
                description: 'Social security contributions',
            );
        }

        if (Money::isPositive($sourceTaxAmount)) {
            $sourceTaxAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::WITHHOLDING_TAX_PAYABLE);
            $lines[] = new JournalLineData(
                accountId: (string) $sourceTaxAccount->id,
                debit: '0',
                credit: $sourceTaxAmount,
                description: 'Withholding tax payable',
            );
        }

        // Build a human-friendly reference: PAY-<INITIALS>-<YYYY>-<MM>.
        // Falls back to a short employee-id slug when initials are unavailable.
        $initials = collect((array) preg_split('/\s+/', trim((string) $employee->fullName())))
            ->filter()
            ->map(fn ($p) => strtoupper(mb_substr((string) $p, 0, 1)))
            ->take(3)
            ->implode('');
        $tag = $initials !== '' ? $initials : substr((string) $slip->employee_id, 0, 4);
        $monthPad = str_pad((string) $slip->period_month, 2, '0', STR_PAD_LEFT);

        $entry = new JournalEntryData(
            date: Carbon::create($slip->period_year, $slip->period_month)->endOfMonth()->toDateString(),
            reference: "PAY-{$tag}-{$slip->period_year}-{$monthPad}",
            description: $description,
            lines: $lines,
        );

        $journalEntry = $this->ledger->postEntry($orgId, $entry);

        $slip->update([
            'journal_entry_id' => $journalEntry->id,
            'posted_at' => now(),
        ]);

        $postedSlip = $slip->fresh();
        $this->sendEmail->execute($postedSlip);

        return $postedSlip;
    }

    /**
     * Split the calculated deductions into liability totals per account and
     * employer cost totals per expense account.
     *
     * Every deduction must reach an account. A contribution that only appears
     * in the employer total without a matching credit unbalances the entry, so
     * an unmapped code is an error here rather than a silent omission.
     *
     * @param  array<string, mixed>  $deductions
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function groupDeductions(SalarySlip $slip, array $deductions): array
    {
        /** @var array<string, DeductionRate> $rates */
        $rates = DeductionRate::query()
            ->where('organization_id', $slip->organization_id)
            ->where(fn ($query) => $query
                ->whereNull('employee_id')
                ->orWhere('employee_id', $slip->employee_id))
            ->get()
            // An employee-specific rate has to come last so it wins the keyBy.
            ->sortBy(fn (DeductionRate $rate): int => $rate->employee_id === null ? 0 : 1)
            ->keyBy('code')
            ->all();

        $liabilities = [];
        $employerCosts = [];

        foreach ($deductions as $code => $amount) {
            $code = (string) $code;
            // A code may well have no configured row of its own — the built-in
            // defaults are not in the table.
            $rate = $rates[$code] ?? null;

            if ($rate === null && ! in_array($code, SwissDeductionService::defaultCodes(), true)) {
                continue;
            }

            $amount = Money::normalize((string) $amount);
            if (! Money::isPositive($amount)) {
                continue;
            }

            $fallbackAccount = self::FALLBACK_LIABILITY_ACCOUNTS[$code] ?? null;
            $liabilityAccount = $rate === null
                ? $fallbackAccount
                : ($rate->account_code ?? $fallbackAccount);

            if ($liabilityAccount === null) {
                throw new UnmappedDeductionException(
                    "Deduction '{$code}' has no liability account. Set account_code on the deduction rate."
                );
            }

            $liabilities[$liabilityAccount] = Money::add($liabilities[$liabilityAccount] ?? '0.00', $amount);

            $type = $rate === null
                ? (str_ends_with($code, '_employer') ? 'employer' : 'employee')
                : $rate->type;

            if ($type === 'employer') {
                $expenseAccount = $rate === null
                    ? AccountCode::SOCIAL_CHARGES_EMPLOYER
                    : ($rate->expense_account_code ?? AccountCode::SOCIAL_CHARGES_EMPLOYER);
                $employerCosts[$expenseAccount] = Money::add($employerCosts[$expenseAccount] ?? '0.00', $amount);
            }
        }

        return [$liabilities, $employerCosts];
    }

    private function ensureSourceTaxApplied(SalarySlip $slip): void
    {
        if ($slip->source_tax_base === null) {
            $this->sourceTax->applyToSlip($slip, $slip->employee);
        }

        if ($slip->source_tax_base === null) {
            return;
        }

        $deductions = $slip->deductions;
        if (array_key_exists('source_tax', $deductions)) {
            return;
        }

        $sourceTaxAmount = Money::normalize((string) ($slip->source_tax_amount ?? '0.00'));
        $deductions['source_tax'] = $sourceTaxAmount;
        $deductions['net_salary'] = Money::subtract($slip->net_salary, $sourceTaxAmount);
        $slip->forceFill([
            'net_salary' => $deductions['net_salary'],
            'deductions' => $deductions,
        ]);

        if ($slip->exists) {
            $slip->saveQuietly();
        }
    }
}
