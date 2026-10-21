<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\VatPeriodLockService;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Projects\Enums\AcquisitionStatus;
use App\Domains\Projects\Models\Acquisition;
use App\Domains\Projects\Models\PlannedExpense;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The expenses still expected in a year (spec 012): salaries and the
 * employer's social contributions from the payroll preview, the due days of
 * recurring expenses, expenses not yet posted, ordered acquisitions, single
 * expenses entered by hand, what cost blocks still plan beyond all that, and
 * the VAT owed to the tax authorities.
 *
 * Amounts are net. VAT is a payment, not a cost: it is listed and carried
 * into the bank outlook (gross) but kept out of the net total.
 *
 * Nothing counts twice: a posted or externally booked salary slip takes its
 * month out, a recurring expense moves its next due date on once it is
 * generated, a posted expense or booked acquisition is no longer open, and a
 * cost block only adds what the other rows on its accounts leave of its plan.
 */
class ExpectedExpensesService
{
    /** Day of the month salaries are paid on. */
    public const PAYDAY = 25;

    /** Days after the end of a quarter its VAT is due. */
    public const VAT_DUE_DAYS = 60;

    /** @var list<string> */
    public const SOURCES = ['salary', 'social', 'recurring', 'expense', 'acquisition', 'planned', 'block', 'vat'];

    public function __construct(
        private GeneratePayrollRunAction $payroll,
        private PlanActualService $plans,
        private VatReportService $vatReports,
        private VatPeriodLockService $vatLocks,
        private LedgerQueryService $ledger,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, by_source: array<string, string>, total: string, cash_total: string, hourly: list<string>}
     */
    public function forYear(string $organizationId, int $year, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $current = (int) $today->year;
        $from = $year === $current ? $today->copy() : Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->startOfDay();

        $rows = [];
        $hourly = [];
        if ($year >= $current) {
            [$salaryRows, $hourly] = $this->salaries($organizationId, $year, (int) $from->month);
            $rows = [
                ...$salaryRows,
                ...$this->recurring($organizationId, $from, $to),
                ...$this->vat($organizationId, $year, $today),
            ];
        }
        $rows = [
            ...$rows,
            ...$this->openExpenses($organizationId, $year, $today),
            ...$this->acquisitions($organizationId, $year, $today),
            ...$this->planned($organizationId, $year, $today),
        ];
        if ($year >= $current) {
            $rows = [...$rows, ...$this->blockRests($organizationId, $year, $today, $rows)];
        }

        // Dated rows by day, those without a date at the end.
        usort($rows, fn (array $a, array $b) => [$a['date'] ?? '9999-99-99', array_search($a['source'], self::SOURCES, true), $a['label']]
            <=> [$b['date'] ?? '9999-99-99', array_search($b['source'], self::SOURCES, true), $b['label']]);

        $bySource = array_fill_keys(self::SOURCES, '0.00');
        $total = '0.00';
        $cash = '0.00';
        foreach ($rows as $row) {
            $bySource[$row['source']] = Money::add($bySource[$row['source']], $row['amount']);
            if ($row['in_result']) {
                $total = Money::add($total, $row['amount']);
            }
            $cash = Money::add($cash, $row['gross']);
        }

        return [
            'rows' => $rows,
            'by_source' => $bySource,
            'total' => $total,
            'cash_total' => $cash,
            'hourly' => $hourly,
        ];
    }

    /**
     * Month by month from the running month to the end of next year: income
     * and expenses still expected (net, for the result), and the bank
     * balance from today's on. The bank counts in money as it moves: jobs at
     * their expected amount plus VAT, open invoices at what they still owe on
     * their due day, expenses gross, and the VAT those future amounts bring
     * (output less input) in the month its quarter is due. Overdue amounts
     * fall into the running month, undated ones into December of their year.
     *
     * @param  array<int, list<array<string, mixed>>>  $payments  year => open payments
     * @param  array<int, array<string, mixed>>  $expenses  year => result of forYear()
     * @param  list<array<string, mixed>>  $invoices  open invoices (SteeringService::openInvoices)
     * @return array{bank_today: string, months: list<array<string, mixed>>}
     */
    public function outlook(string $organizationId, array $payments, array $expenses, array $invoices = [], ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $first = $today->copy()->startOfMonth();
        $last = Carbon::create((int) $today->year + 1, 12, 1);

        // Month keys from the running month on; one sum per month and kind.
        $keys = [];
        for ($m = $first->copy(); $m->lessThanOrEqualTo($last); $m->addMonth()) {
            $keys[] = $m->format('Y-m');
        }
        $income = array_fill_keys($keys, '0.00');
        $costs = array_fill_keys($keys, '0.00');
        $cashIn = array_fill_keys($keys, '0.00');
        $cashOut = array_fill_keys($keys, '0.00');
        $vatByQuarter = [];
        $slot = function (?string $date, int $year) use ($first, $income): ?string {
            $key = $date === null ? "{$year}-12" : substr($date, 0, 7);

            return $key < $first->format('Y-m') ? $first->format('Y-m') : (isset($income[$key]) ? $key : null);
        };
        $addVat = function (string $key, string $amount) use (&$vatByQuarter): void {
            $quarter = substr($key, 0, 4).'-'.intdiv((int) substr($key, 5, 2) + 2, 3);
            $vatByQuarter[$quarter] = Money::add($vatByQuarter[$quarter] ?? '0.00', $amount);
        };
        $rate = (string) (VatRate::withoutGlobalScopes()->where('organization_id', $organizationId)->where('is_default', true)->value('rate') ?? '0');

        foreach ($payments as $year => $list) {
            foreach ($list as $payment) {
                $key = $slot($payment['sort'] ?? null, (int) $year);
                if ($key !== null) {
                    $vat = Money::percentage((string) $payment['expected'], $rate);
                    $income[$key] = Money::add($income[$key], (string) $payment['expected']);
                    $cashIn[$key] = Money::add($cashIn[$key], Money::add((string) $payment['expected'], $vat));
                    $addVat($key, $vat);
                }
            }
        }
        foreach ($invoices as $invoice) {
            $key = $slot($invoice['due_date'] ?? null, (int) $today->year);
            if ($key !== null) {
                // Its VAT is booked already and paid with its quarter.
                $cashIn[$key] = Money::add($cashIn[$key], (string) $invoice['gross']);
            }
        }
        foreach ($expenses as $year => $result) {
            foreach ($result['rows'] as $row) {
                $key = $slot($row['date'], (int) $year);
                if ($key === null) {
                    continue;
                }
                if ($row['in_result']) {
                    $costs[$key] = Money::add($costs[$key], $row['amount']);
                    // Input VAT comes back with the quarter's settlement.
                    $addVat($key, Money::negate(Money::subtract($row['gross'], $row['amount'])));
                }
                $cashOut[$key] = Money::add($cashOut[$key], $row['gross']);
            }
        }
        foreach ($vatByQuarter as $quarter => $owed) {
            [$y, $q] = array_map('intval', explode('-', $quarter));
            $due = Carbon::create($y, $q * 3, 1)->endOfMonth()->startOfDay()->addDays(self::VAT_DUE_DAYS)->format('Y-m');
            if (isset($cashOut[$due])) {
                $cashOut[$due] = Money::add($cashOut[$due], $owed);
            }
        }

        $bankToday = $this->bankBalance($organizationId, $today);
        $bank = $bankToday;
        $result = '0.00';
        $out = [];
        foreach ($keys as $key) {
            $result = Money::add($result, Money::subtract($income[$key], $costs[$key]));
            $bank = Money::subtract(Money::add($bank, $cashIn[$key]), $cashOut[$key]);
            $out[] = ['key' => $key, 'income' => $income[$key], 'expenses' => $costs[$key], 'cash_in' => $cashIn[$key], 'cash_out' => $cashOut[$key], 'result' => $result, 'bank' => $bank];
        }

        return ['bank_today' => $bankToday, 'months' => $out];
    }

    /**
     * What a salary slip costs the company, split into the salary (gross and
     * reimbursed allowances) and the employer's contributions.
     *
     * @param  array<string, mixed>  $deductions
     * @param  array<string, mixed>  $adjustments
     * @return array{salary: string, employer: string}
     */
    public static function slipCost(string $gross, array $deductions, array $adjustments): array
    {
        $employer = '0.00';
        foreach (self::employerContributions($deductions) as $amount) {
            $employer = Money::add($employer, $amount);
        }

        return [
            'salary' => Money::add($gross, (string) ($adjustments['reimbursement_amount'] ?? '0')),
            'employer' => $employer,
        ];
    }

    /**
     * Each employer contribution once by its code; total_employer sums them.
     *
     * @param  array<string, mixed>  $deductions
     * @return array<string, string>
     */
    private static function employerContributions(array $deductions): array
    {
        $contributions = [];
        foreach ($deductions as $code => $amount) {
            if (str_ends_with((string) $code, '_employer') && ! str_starts_with((string) $code, 'total_') && is_numeric($amount)) {
                $contributions[(string) $code] = Money::normalize((string) $amount);
            }
        }

        return $contributions;
    }

    /**
     * One row per person and month without a counted slip, and per month one
     * row of employer contributions for each account they are booked on.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function salaries(string $organizationId, int $year, int $fromMonth): array
    {
        $counted = SalarySlip::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('period_year', $year)
            ->where(fn ($q) => $q->whereNotNull('posted_at')->orWhere('booked_externally', true))
            ->get(['employee_id', 'period_month'])
            ->map(fn (SalarySlip $s) => "{$s->employee_id}-{$s->period_month}")
            ->flip();

        // The accounts the posting would use (PostPayrollAction): a rate set
        // for one person wins over the organization's rate of the same code.
        $organization = Organization::find($organizationId);
        $salaryAccount = $organization->payroll_salary_account_code ?? AccountCode::SALARIES;
        $reimbursementAccount = $organization->payroll_reimbursement_account_code ?? AccountCode::GENERAL_EXPENSE;
        $contributionAccounts = [];
        DeductionRate::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByRaw('employee_id IS NOT NULL')
            ->get(['employee_id', 'code', 'expense_account_code'])
            ->each(function (DeductionRate $rate) use (&$contributionAccounts): void {
                $contributionAccounts[$rate->employee_id ?? ''][$rate->code] = $rate->expense_account_code;
            });

        $rows = [];
        for ($month = $fromMonth; $month <= 12; $month++) {
            $date = Carbon::create($year, $month, self::PAYDAY)->toDateString();
            $employer = [];
            foreach ($this->payroll->preview($organizationId, $month, $year) as $slip) {
                if ($counted->has("{$slip->employee_id}-{$month}")) {
                    continue;
                }
                $cost = self::slipCost((string) $slip->gross_salary, (array) $slip->deductions, (array) $slip->adjustments);
                $personEmployer = '0.00';
                foreach (self::employerContributions((array) $slip->deductions) as $code => $amount) {
                    $account = $contributionAccounts[$slip->employee_id][$code]
                        ?? $contributionAccounts[''][$code]
                        ?? AccountCode::SOCIAL_CHARGES_EMPLOYER;
                    $employer[$account] = Money::add($employer[$account] ?? '0.00', $amount);
                    $personEmployer = Money::add($personEmployer, $amount);
                }
                $snapshot = (array) $slip->employee_snapshot;
                $accounts = [$salaryAccount => Money::add((string) $slip->gross_salary, '0')];
                $reimbursement = (string) (((array) $slip->adjustments)['reimbursement_amount'] ?? '0');
                if (Money::isPositive($reimbursement)) {
                    $accounts[$reimbursementAccount] = Money::add($accounts[$reimbursementAccount] ?? '0.00', $reimbursement);
                }
                $rows[] = [
                    ...$this->row($date, 'salary', trim(($snapshot['first_name'] ?? '').' '.($snapshot['last_name'] ?? '')), $salaryAccount, $cost['salary'], $cost['salary'],
                        link: '/payroll/employees/'.$slip->employee_id, month: $month),
                    // The allowance is posted on its own account; the person
                    // and their employer part feed the steering per person.
                    'accounts' => $accounts,
                    'employee_id' => $slip->employee_id,
                    'employer' => $personEmployer,
                ];
            }
            ksort($employer);
            foreach ($employer as $account => $amount) {
                if (Money::isPositive($amount)) {
                    $rows[] = $this->row($date, 'social', null, (string) $account, $amount, $amount, month: $month);
                }
            }
        }

        // Hourly wages have no estimate: the hours are not known ahead.
        $hourly = Employee::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('salary_type', 'hourly')
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhereDate('exit_date', '>=', Carbon::create($year, $fromMonth, 1)))
            ->orderBy('last_name')
            ->get(['first_name', 'last_name'])
            ->map(fn (Employee $e) => trim("{$e->first_name} {$e->last_name}"))
            ->values()
            ->all();

        /** @var list<string> $hourly */
        return [$rows, $hourly];
    }

    /**
     * Each due day of an active recurring expense from its next due date,
     * within the given days and up to its end date.
     *
     * @return list<array<string, mixed>>
     */
    private function recurring(string $organizationId, Carbon $from, Carbon $to): array
    {
        $schedules = RecurringExpense::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('supplier:id,name')
            ->orderBy('id')
            ->get();
        $codes = $this->accountCodes($organizationId, $schedules->pluck('expense_account_id')->filter()->all());

        $rows = [];
        foreach ($schedules as $schedule) {
            $label = (string) ($schedule->description ?: ($schedule->supplier->name ?? $schedule->vendor ?? $schedule->category));
            $net = Money::normalize((string) $schedule->amount);
            $gross = Money::add($net, (string) ($schedule->vat_amount ?? '0'));
            for ($day = $schedule->next_due_date->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day = $schedule->frequency->nextDate($day, $schedule->anchorDay())) {
                if ($schedule->end_date !== null && $day->greaterThan($schedule->end_date)) {
                    break;
                }
                if ($day->lessThan($from)) {
                    continue;
                }
                $rows[] = $this->row($day->toDateString(), 'recurring', $label, $codes[$schedule->expense_account_id] ?? $schedule->expense_account_code, $net, $gross,
                    link: '/expenses/recurring/'.$schedule->uuid.'/edit');
            }
        }

        return $rows;
    }

    /**
     * Expenses not yet posted, dated up to the end of the year; for a year
     * that is not the running one only those dated in it.
     *
     * @return list<array<string, mixed>>
     */
    private function openExpenses(string $organizationId, int $year, Carbon $today): array
    {
        $expenses = Expense::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('status', [ExpenseStatus::Pending->value, ExpenseStatus::Approved->value])
            ->where(fn ($q) => $this->inYear($q, 'date', $year, $today))
            ->with('supplier:id,name')
            ->orderBy('date')
            ->get();
        $codes = $this->accountCodes($organizationId, $expenses->pluck('expense_account_id')->filter()->all());

        return array_values($expenses->map(fn (Expense $expense) => $this->row(
            $expense->date->toDateString(),
            'expense',
            (string) ($expense->supplier->name ?? $expense->vendor ?? $expense->description ?? $expense->category),
            $codes[$expense->expense_account_id] ?? $expense->expense_account_code,
            Money::normalize((string) $expense->amount),
            $expense->gross_amount,
            overdue: $expense->date->lessThan($today),
            link: '/expenses/'.$expense->id,
        ))->all());
    }

    /**
     * Acquisitions ordered and not yet booked.
     *
     * @return list<array<string, mixed>>
     */
    private function acquisitions(string $organizationId, int $year, Carbon $today): array
    {
        $acquisitions = Acquisition::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', AcquisitionStatus::Ordered->value)
            ->whereNull('journal_entry_id')
            ->where(fn ($q) => $this->inYear($q, 'purchased_on', $year, $today))
            ->orderBy('purchased_on')
            ->get();

        return array_values($acquisitions->map(fn (Acquisition $a) => $this->row(
            $a->purchased_on->toDateString(),
            'acquisition',
            trim($a->item.($a->supplier ? " · {$a->supplier}" : '')),
            null,
            Money::normalize((string) ($a->net_amount ?? $a->gross_amount)),
            Money::normalize((string) $a->gross_amount),
            overdue: $a->purchased_on->lessThan($today),
            link: '/projects/acquisitions?year='.$a->purchased_on->year,
        ))->all());
    }

    /**
     * Single expenses entered by hand and not yet marked done.
     *
     * @return list<array<string, mixed>>
     */
    private function planned(string $organizationId, int $year, Carbon $today): array
    {
        $planned = PlannedExpense::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('done_at')
            ->where(fn ($q) => $this->inYear($q, 'date', $year, $today))
            ->orderBy('date')
            ->get();

        return array_values($planned->map(fn (PlannedExpense $p) => [
            ...$this->row(
                $p->date->toDateString(),
                'planned',
                $p->label,
                $p->account_code,
                // May be negative: an expected refund.
                Money::add((string) $p->amount, '0'),
                Money::add((string) $p->amount, (string) $p->vat_amount),
                overdue: $p->date->lessThan($today),
            ),
            'id' => $p->id,
            'vat_amount' => Money::normalize((string) $p->vat_amount),
        ])->all());
    }

    /**
     * What each cost block still plans beyond what is booked and what the
     * other rows on its accounts already expect; undated.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function blockRests(string $organizationId, int $year, Carbon $today, array $rows): array
    {
        $expected = [];
        foreach ($rows as $row) {
            if (! $row['in_result']) {
                continue;
            }
            foreach ($row['accounts'] ?? ($row['account'] !== null ? [$row['account'] => $row['amount']] : []) as $account => $amount) {
                $expected[$account] = Money::add($expected[$account] ?? '0.00', (string) $amount);
            }
        }

        $rests = [];
        foreach ($this->plans->forYear($organizationId, $year, $today)['groups'] as $group) {
            $covered = array_reduce($group['accounts'], fn (string $sum, array $a) => Money::add($sum, $expected[$a['code']] ?? '0.00'), '0.00');
            $rest = Money::subtract((string) $group['rest'], $covered);
            if (Money::isPositive($rest)) {
                $rests[] = $this->row(null, 'block', $group['name'], null, $rest, $rest, link: '/projects/plan?year='.$year);
            }
        }

        return $rests;
    }

    /**
     * VAT of the quarters not yet settled whose payment falls in the year
     * (the running quarter as far as booked), and in the running year what
     * settled quarters still owe on the settlement account.
     *
     * @return list<array<string, mixed>>
     */
    private function vat(string $organizationId, int $year, Carbon $today): array
    {
        $rows = [];
        if ($year === (int) $today->year) {
            $account = Account::withoutGlobalScopes()->where('organization_id', $organizationId)->where('code', AccountCode::VAT_PAYABLE_AFC)->first();
            $open = $account === null ? '0.00' : $this->ledger->accountBalance((int) $account->id, null, $today->toDateString());
            if (Money::compare($open, '0') !== 0) {
                $rows[] = $this->row($today->toDateString(), 'vat', 'settled', AccountCode::VAT_PAYABLE_AFC, '0.00', $open,
                    link: '/accounting/chart-of-accounts');
            }
        }

        // Q4 of the year before is paid in this one.
        for ($q = 0; $q < 5; $q++) {
            $start = Carbon::create($year - 1, 10, 1)->addMonths(3 * $q)->startOfDay();
            $end = $start->copy()->addMonths(3)->subDay();
            $due = $end->copy()->addDays(self::VAT_DUE_DAYS);
            if ((int) $due->year !== $year || $start->greaterThan($today) || $this->vatLocks->isLocked($organizationId, $end->toDateString())) {
                continue;
            }
            $report = $this->vatReports->generate($organizationId, $start->toDateString(), $end->toDateString());
            $owed = Money::subtract((string) ($report['vat_payable'] ?? '0'), (string) ($report['vat_credit'] ?? '0'));
            if (Money::compare($owed, '0') === 0) {
                continue;
            }
            $rows[] = $this->row($due->toDateString(), 'vat', $end->greaterThan($today) ? 'running' : 'open', null, '0.00', $owed,
                overdue: $due->lessThan($today),
                link: '/reports/vat?from='.$start->toDateString().'&to='.$end->toDateString(),
                quarter: 'Q'.intdiv((int) $start->month + 2, 3).' '.$start->year);
        }

        return $rows;
    }

    /**
     * What the active bank accounts hold today in the ledger.
     */
    private function bankBalance(string $organizationId, Carbon $today): string
    {
        $balance = '0.00';
        $accounts = BankAccount::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->unique();
        foreach ($accounts as $accountId) {
            $balance = Money::add($balance, $this->ledger->accountBalance((int) $accountId, null, $today->toDateString()));
        }

        return $balance;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int|string, string>
     */
    private function accountCodes(string $organizationId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('id', array_unique($ids))
            ->pluck('code', 'id')
            ->all();
    }

    /**
     * Dated in the year; for the running year also anything earlier still open.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function inYear($query, string $column, int $year, Carbon $today): void
    {
        $query->whereDate($column, '<=', "{$year}-12-31");
        if ($year !== (int) $today->year) {
            $query->whereDate($column, '>=', "{$year}-01-01");
        }
    }

    /**
     * One expected row. VAT rows are payments: their amount is what is owed,
     * and they stay out of the net result.
     *
     * @return array<string, mixed>
     */
    private function row(?string $date, string $source, ?string $label, ?string $account, string $amount, string $gross, bool $overdue = false,
        ?string $link = null, ?int $month = null, ?string $quarter = null): array
    {
        return [
            'date' => $date,
            'source' => $source,
            'label' => $label,
            'month' => $month,
            'quarter' => $quarter,
            'account' => $account,
            'amount' => $source === 'vat' ? $gross : $amount,
            'gross' => $gross,
            'in_result' => $source !== 'vat',
            'overdue' => $overdue,
            'link' => $link,
        ];
    }
}
