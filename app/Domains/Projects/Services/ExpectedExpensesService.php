<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Models\Account;
use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The expenses still expected in a year, net (spec 012, stage 1): salaries
 * and the employer's social contributions from the payroll preview, the
 * due days of recurring expenses, and expenses not yet posted.
 *
 * Nothing counts twice: a posted or externally booked salary slip takes its
 * month out, a recurring expense moves its next due date on once it is
 * generated, and a posted expense is no longer open.
 */
class ExpectedExpensesService
{
    /** Day of the month salaries are paid on. */
    public const PAYDAY = 25;

    public function __construct(private GeneratePayrollRunAction $payroll) {}

    /**
     * @return array{rows: list<array<string, mixed>>, by_source: array<string, string>, total: string, hourly: list<string>}
     */
    public function forYear(string $organizationId, int $year, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $from = $year === (int) $today->year ? $today->copy() : Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->startOfDay();

        $rows = [];
        $hourly = [];
        if ($year >= (int) $today->year) {
            [$salaryRows, $hourly] = $this->salaries($organizationId, $year, (int) $from->month);
            $rows = [...$salaryRows, ...$this->recurring($organizationId, $from, $to)];
        }
        $rows = [...$rows, ...$this->openExpenses($organizationId, $year, $today)];

        usort($rows, fn (array $a, array $b) => [$a['date'], $a['source'], $a['label']] <=> [$b['date'], $b['source'], $b['label']]);

        $bySource = array_fill_keys(['salary', 'social', 'recurring', 'expense'], '0.00');
        foreach ($rows as $row) {
            $bySource[$row['source']] = Money::add($bySource[$row['source']], $row['amount']);
        }

        return [
            'rows' => $rows,
            'by_source' => $bySource,
            'total' => array_reduce($bySource, fn (string $sum, string $amount) => Money::add($sum, $amount), '0.00'),
            'hourly' => $hourly,
        ];
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
        foreach ($deductions as $code => $amount) {
            // Each employer contribution once; total_employer sums them.
            if (str_ends_with((string) $code, '_employer') && ! str_starts_with((string) $code, 'total_') && is_numeric($amount)) {
                $employer = Money::add($employer, (string) $amount);
            }
        }

        return [
            'salary' => Money::add($gross, (string) ($adjustments['reimbursement_amount'] ?? '0')),
            'employer' => $employer,
        ];
    }

    /**
     * One row per person and month without a counted slip, and one row of
     * employer contributions per month.
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

        $rows = [];
        for ($month = $fromMonth; $month <= 12; $month++) {
            $date = Carbon::create($year, $month, self::PAYDAY)->toDateString();
            $employer = '0.00';
            foreach ($this->payroll->preview($organizationId, $month, $year) as $slip) {
                if ($counted->has("{$slip->employee_id}-{$month}")) {
                    continue;
                }
                $cost = self::slipCost((string) $slip->gross_salary, (array) $slip->deductions, (array) $slip->adjustments);
                $employer = Money::add($employer, $cost['employer']);
                $snapshot = (array) $slip->employee_snapshot;
                $rows[] = [
                    'date' => $date,
                    'source' => 'salary',
                    'label' => trim(($snapshot['first_name'] ?? '').' '.($snapshot['last_name'] ?? '')),
                    'month' => $month,
                    'account' => null,
                    'amount' => $cost['salary'],
                    'overdue' => false,
                    'link' => '/payroll/employees/'.$slip->employee_id,
                ];
            }
            if (Money::isPositive($employer)) {
                $rows[] = [
                    'date' => $date,
                    'source' => 'social',
                    'label' => null,
                    'month' => $month,
                    'account' => null,
                    'amount' => $employer,
                    'overdue' => false,
                    'link' => null,
                ];
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
            for ($day = $schedule->next_due_date->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day = $schedule->frequency->nextDate($day)) {
                if ($schedule->end_date !== null && $day->greaterThan($schedule->end_date)) {
                    break;
                }
                if ($day->lessThan($from)) {
                    continue;
                }
                $rows[] = [
                    'date' => $day->toDateString(),
                    'source' => 'recurring',
                    'label' => $label,
                    'month' => null,
                    'account' => $codes[$schedule->expense_account_id] ?? $schedule->expense_account_code,
                    'amount' => Money::normalize((string) $schedule->amount),
                    'overdue' => false,
                    'link' => '/expenses/recurring/'.$schedule->uuid.'/edit',
                ];
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
            ->whereDate('date', '<=', "{$year}-12-31")
            ->when($year !== (int) $today->year, fn ($q) => $q->whereDate('date', '>=', "{$year}-01-01"))
            ->with('supplier:id,name')
            ->orderBy('date')
            ->get();
        $codes = $this->accountCodes($organizationId, $expenses->pluck('expense_account_id')->filter()->all());

        return array_values($expenses->map(fn (Expense $expense) => [
            'date' => $expense->date->toDateString(),
            'source' => 'expense',
            'label' => (string) ($expense->supplier->name ?? $expense->vendor ?? $expense->description ?? $expense->category),
            'month' => null,
            'account' => $codes[$expense->expense_account_id] ?? $expense->expense_account_code,
            'amount' => Money::normalize((string) $expense->amount),
            'overdue' => $expense->date->lessThan($today),
            'link' => '/expenses/'.$expense->id,
        ])->all());
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
}
