<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Accounting\Support\AccountDisplayName;
use App\Domains\Expenses\Services\RecurringExpensePlan;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Plan against actual per cost block (spec 009, stage 3).
 *
 * Actual is what the block's accounts carry in the year up to the given
 * day, posted and without year-end closing. Shares allocated to customer
 * projects are not internal costs: they are taken out and shown apart.
 */
class PlanActualService
{
    public function __construct(
        private ProjectReportService $reports,
        private RecurringExpensePlan $recurring,
    ) {}

    /**
     * @return array{
     *     groups: array<int, array<string, mixed>>,
     *     ungrouped: array<int, array<string, mixed>>,
     *     totals: array<string, string>,
     *     elapsed: string,
     * }
     */
    public function forYear(string $organizationId, int $year, ?Carbon $asOf = null, bool $withKeys = false): array
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $to = $asOf->lessThan($yearEnd) ? $asOf : $yearEnd;

        // Days of the year that have passed, for the projection of running costs.
        $daysInYear = (int) $from->diffInDays($yearEnd) + 1;
        $daysPassed = $asOf->lessThan($from) ? 0 : (int) $from->diffInDays($to) + 1;
        $elapsed = bcdiv((string) $daysPassed, (string) $daysInYear, 6);

        $balances = $to->lessThan($from) ? collect() : DB::table('transaction_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->where('journal_entries.organization_id', $organizationId)
            ->where('journal_entries.is_posted', true)
            ->whereBetween('journal_entries.date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('journal_entries.type')->orWhere('journal_entries.type', '<>', 'year_end_closing'))
            ->groupBy('transaction_lines.account_id')
            ->selectRaw('transaction_lines.account_id, SUM(transaction_lines.debit) AS debit, SUM(transaction_lines.credit) AS credit')
            ->get()
            ->keyBy('account_id');

        $customerProjects = Project::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('kind', ProjectKind::Project->value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $customerShare = $to->lessThan($from)
            ? []
            : $this->reports->totalsByAccount($organizationId, $from->toDateString(), $to->toDateString(), $customerProjects);

        $accounts = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->get(['id', 'code', 'name', 'type'])
            ->keyBy('id');

        $budgets = Budget::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('fiscal_year', $year)
            ->pluck('monthly_amount', 'account_id');
        $recurring = $this->recurring->forYear($organizationId, $year);

        $actualOf = function (int $accountId) use ($balances, $accounts): string {
            $row = $balances->get($accountId);
            if ($row === null) {
                return '0.00';
            }

            return $accounts->get($accountId)?->type === AccountType::Revenue
                ? Money::subtract((string) $row->credit, (string) $row->debit)
                : Money::subtract((string) $row->debit, (string) $row->credit);
        };

        $groups = BudgetGroup::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('year', $year)
            ->with(['accounts:id,code,name,type', 'people'])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $grouped = [];
        $rows = [];
        foreach ($groups as $group) {
            $booked = '0.00';
            $customer = '0.00';
            $budgeted = '0.00';
            $planned = [];
            foreach ($group->accounts as $account) {
                $grouped[$account->id] = true;
                $booked = Money::add($booked, $actualOf($account->id));
                $customer = Money::add($customer, $customerShare[$account->code] ?? '0.00');
                $budgeted = Money::add($budgeted, Money::multiply((string) ($budgets[$account->id] ?? '0'), '12'));
                // Recurring expenses add to the plan of their account.
                foreach ($recurring[$account->id] ?? [] as $item) {
                    $budgeted = Money::add($budgeted, $item['yearly']);
                    $planned[] = $item + ['account_code' => $account->code];
                }
            }

            $actual = Money::subtract($booked, $customer);
            $plan = $group->plan_amount !== null ? (string) $group->plan_amount : Money::round($budgeted);
            $projection = $group->kind === BudgetGroupKind::Running && $daysPassed > 0
                ? Money::round(bcdiv(bcmul($actual, (string) $daysInYear, 6), (string) $daysPassed, 6))
                : $actual;

            $rows[] = [
                'id' => $group->id,
                'name' => $group->name,
                'kind' => $group->kind->value,
                'plan_from_budgets' => $group->plan_amount === null,
                'recurring' => $planned,
                'accounts' => $group->accounts->sortBy('code')->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->display_name])->values()->all(),
                'notes' => $group->notes,
                // Carry and credit keys are steering data: owners only.
                'people' => ! $withKeys ? [] : $group->people->map(fn ($e) => [
                    'employee_id' => $e->id,
                    'carry_share' => (string) (float) data_get($e, 'pivot.carry_share'),
                    'credit_share' => (string) (float) data_get($e, 'pivot.credit_share'),
                ])->values()->all(),
                'plan' => $plan,
                'actual' => $actual,
                'customer_projects' => $customer,
                'rest' => Money::subtract($plan, $actual),
                'projection' => $projection,
                'over_plan' => Money::isPositive($plan) && Money::compare($projection, $plan) > 0,
                'progress' => Money::isPositive($plan) ? (int) round((float) bcdiv(bcmul($actual, '100', 4), $plan, 4)) : null,
            ];
        }

        $ungrouped = [];
        foreach ($accounts as $account) {
            if (isset($grouped[$account->id]) || $account->type !== AccountType::Expense) {
                continue;
            }
            $actual = Money::subtract($actualOf($account->id), $customerShare[$account->code] ?? '0.00');
            if (Money::compare($actual, '0') === 0) {
                continue;
            }
            $ungrouped[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => AccountDisplayName::for((string) $account->code, (string) $account->name),
                'actual' => $actual,
            ];
        }
        usort($ungrouped, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        $totals = ['plan' => '0.00', 'actual' => '0.00', 'customer_projects' => '0.00', 'rest' => '0.00', 'projection' => '0.00'];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] = Money::add($totals[$field], (string) $row[$field]);
            }
        }

        return [
            'groups' => $rows,
            'ungrouped' => $ungrouped,
            'totals' => $totals,
            'elapsed' => $elapsed,
        ];
    }

    /**
     * Copy the blocks of the year before into a year, skipping names that
     * already exist there.
     */
    public function copyFromPreviousYear(string $organizationId, int $year): int
    {
        $existing = BudgetGroup::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('year', $year)
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower((string) $n))
            ->all();
        $taken = BudgetGroup::withoutGlobalScopes()
            ->join('budget_group_accounts', 'budget_group_accounts.budget_group_id', '=', 'budget_groups.id')
            ->where('budget_groups.organization_id', $organizationId)
            ->where('budget_groups.year', $year)
            ->pluck('budget_group_accounts.account_id')
            ->all();

        $copied = 0;
        $previous = BudgetGroup::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('year', $year - 1)
            ->with('accounts:id')
            ->get();

        DB::transaction(function () use ($previous, $existing, $taken, $year, &$copied): void {
            foreach ($previous as $group) {
                if (in_array(mb_strtolower($group->name), $existing, true)) {
                    continue;
                }
                $copy = BudgetGroup::create([
                    'organization_id' => $group->organization_id,
                    'year' => $year,
                    'name' => $group->name,
                    'kind' => $group->kind,
                    'plan_amount' => $group->plan_amount,
                    'position' => $group->position,
                    'notes' => $group->notes,
                ]);
                $copy->accounts()->sync(array_values(array_diff($group->accounts->pluck('id')->all(), $taken)));
                $copied++;
            }
        });

        return $copied;
    }
}
