<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Contracts\SourceTaxServiceInterface;
use App\Domains\Payroll\Exceptions\PostingDateOutsidePeriodException;
use App\Domains\Payroll\Exceptions\UnmappedDeductionException;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\SwissDeductionService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Lang;

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

        // Explicitly, not via $employee->organization: lazy loading is off
        // installation-wide and an unloaded relation throws.
        $organization = Organization::query()->whereKey($orgId)->first();

        // The organization's language, not the language of whoever presses the
        // button: a journal text is written once and read for years.
        $locale = $organization === null || $organization->locale === null
            ? app()->getLocale()
            : $organization->locale;
        $period = str_pad((string) $slip->period_month, 2, '0', STR_PAD_LEFT).'/'.$slip->period_year;
        $description = __('app.payroll_journal_description', [
            'name' => $employee->fullName(),
            'period' => $period,
        ], $locale);
        $salaryAccount = $this->ledgerQuery->resolveAccount(
            $orgId,
            $organization?->payroll_salary_account_code === null
                ? AccountCode::SALARIES
                : $organization->payroll_salary_account_code,
        );
        $bankAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::BANK_CASH);

        $sourceTaxAmount = Money::normalize((string) ($deductions['source_tax'] ?? $slip->source_tax_amount ?? '0.00'));

        [$liabilities, $employerCosts] = $this->groupDeductions($slip, $deductions, $locale);

        $lines = [];

        // Debit: Gross salary
        $lines[] = new JournalLineData(
            accountId: (string) $salaryAccount->id,
            debit: $slip->gross_salary,
            credit: '0',
            description: __('app.payroll_journal_gross_salary', ['name' => $employee->fullName()], $locale),
        );

        // Debit: Employer contributions, one line per contribution rather than
        // one per account. The sums are the same either way; spelling them out
        // is what lets a reader check AHV against FAK against BVG in the
        // journal instead of only on the salary slip.
        foreach ($employerCosts as $cost) {
            $account = $this->ledgerQuery->resolveAccount($orgId, $cost['account']);
            $lines[] = new JournalLineData(
                accountId: (string) $account->id,
                debit: $cost['amount'],
                credit: '0',
                description: $cost['description'],
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
                description: __('app.payroll_journal_reimbursement', ['name' => $employee->fullName()], $locale),
            );
        }

        // Credit: Bank (net salary)
        $lines[] = new JournalLineData(
            accountId: (string) $bankAccount->id,
            debit: '0',
            credit: $slip->net_salary,
            description: __('app.payroll_journal_net_salary', ['name' => $employee->fullName()], $locale),
        );

        // Credit: one liability line per contribution, named.
        foreach ($liabilities as $liability) {
            $account = $this->ledgerQuery->resolveAccount($orgId, $liability['account']);
            $lines[] = new JournalLineData(
                accountId: (string) $account->id,
                debit: '0',
                credit: $liability['amount'],
                description: $liability['description'],
            );
        }

        if (Money::isPositive($sourceTaxAmount)) {
            $sourceTaxAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::WITHHOLDING_TAX_PAYABLE);
            $lines[] = new JournalLineData(
                accountId: (string) $sourceTaxAccount->id,
                debit: '0',
                credit: $sourceTaxAmount,
                description: __('app.payroll_journal_withholding_tax', [], $locale),
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
            date: self::postingDate($slip, $organization)->toDateString(),
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
     * The date the entry is written under.
     *
     * The slip's own date wins, then the day of the month the organization
     * pays on, then the last day of the month — which is what every slip got
     * before a date could be chosen at all.
     *
     * @throws PostingDateOutsidePeriodException
     */
    public static function postingDate(SalarySlip $slip, ?Organization $organization): Carbon
    {
        $periodStart = Carbon::create($slip->period_year, $slip->period_month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth()->startOfDay();

        if ($slip->posting_date !== null) {
            $chosen = Carbon::parse($slip->posting_date)->startOfDay();

            if ($chosen->lessThan($periodStart) || $chosen->greaterThan($periodEnd)) {
                throw new PostingDateOutsidePeriodException(
                    "Posting date {$chosen->toDateString()} is outside the payroll month "
                    ."{$periodStart->format('m/Y')}."
                );
            }

            return $chosen;
        }

        $payday = $organization?->payroll_payday;

        if ($payday === null || $payday < 1) {
            return $periodEnd;
        }

        // A payday of 31 in a short month is that month's last day.
        return $periodStart->copy()->setDay(min($payday, $periodStart->daysInMonth));
    }

    /**
     * Split the calculated deductions into one liability line and, for an
     * employer share, one expense line per contribution.
     *
     * Every deduction must reach an account. A contribution that only appears
     * in the employer total without a matching credit unbalances the entry, so
     * an unmapped code is an error here rather than a silent omission.
     *
     * @param  array<string, mixed>  $deductions
     * @return array{0: array<int, array{account: string, amount: string, description: string}>, 1: array<int, array{account: string, amount: string, description: string}>}
     */
    private function groupDeductions(SalarySlip $slip, array $deductions, string $locale): array
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
        $names = $this->deductionNames($deductions, $rates, $locale);

        foreach ($deductions as $code => $amount) {
            $code = (string) $code;
            // The named breakdown lives in the same array and is not a figure.
            if (! is_scalar($amount)) {
                continue;
            }

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

            $name = $names[$code] ?? $code;

            $fallbackAccount = self::FALLBACK_LIABILITY_ACCOUNTS[$code] ?? null;
            $liabilityAccount = $rate === null
                ? $fallbackAccount
                : ($rate->account_code ?? $fallbackAccount);

            if ($liabilityAccount === null) {
                throw new UnmappedDeductionException(
                    "Deduction '{$code}' has no liability account. Set account_code on the deduction rate."
                );
            }

            $liabilities[] = [
                'account' => $liabilityAccount,
                'amount' => $amount,
                'description' => $name,
            ];

            $type = $rate === null
                ? (str_ends_with($code, '_employer') ? 'employer' : 'employee')
                : $rate->type;

            if ($type === 'employer') {
                $expenseAccount = $rate === null
                    ? AccountCode::SOCIAL_CHARGES_EMPLOYER
                    : ($rate->expense_account_code ?? AccountCode::SOCIAL_CHARGES_EMPLOYER);
                $employerCosts[] = [
                    'account' => $expenseAccount,
                    'amount' => $amount,
                    'description' => $name,
                ];
            }
        }

        return [$liabilities, $employerCosts];
    }

    /**
     * The name to write on a deduction's journal lines.
     *
     * The breakdown frozen into the slip wins, so a rate renamed later does not
     * rewrite what an old entry says. Then the rate itself, and for the
     * built-in defaults a translated label — an installation that configured
     * nothing should not read "avs_employee" in its books.
     *
     * @param  array<string, mixed>  $deductions
     * @param  array<string, DeductionRate>  $rates
     * @return array<string, string>
     */
    private function deductionNames(array $deductions, array $rates, string $locale): array
    {
        $names = [];

        foreach ($rates as $code => $rate) {
            $names[(string) $code] = (string) $rate->name;
        }

        foreach (SwissDeductionService::defaultCodes() as $code) {
            if (isset($names[$code]) || ! Lang::has('app.'.$code, $locale)) {
                continue;
            }

            $names[$code] = __('app.'.$code, [], $locale);
        }

        /** @var array<int, array{code?: string, name?: string}> $lines */
        $lines = is_array($deductions[SwissDeductionService::LINES_KEY] ?? null)
            ? $deductions[SwissDeductionService::LINES_KEY]
            : [];

        foreach ($lines as $line) {
            if (isset($line['code'], $line['name']) && $line['name'] !== '') {
                $names[(string) $line['code']] = (string) $line['name'];
            }
        }

        return $names;
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
