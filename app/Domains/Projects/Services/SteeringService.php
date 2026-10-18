<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Models\Budget;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectShare;
use App\Domains\Projects\Models\SteeringItem;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contribution and drawings per person, and what is left to play with
 * (spec 009, stage 5).
 *
 * Contribution: each customer project's value shared by its key (its own,
 * or the nearest one above it). The value is the booked net once revenue
 * is booked, before that the expected amount less booked costs.
 * Effect of cost blocks: who did the work is credited the planned amount,
 * everyone bears their part by the carry key. Drawn: the planned drawings
 * plus acquisitions; booked salary is shown beside it for comparison.
 */
class SteeringService
{
    public function __construct(
        private ProjectReportService $reports,
        private AcquisitionService $acquisitions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forYear(string $organizationId, int $year): array
    {
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";

        // Active people, and anyone else a key, plan item or acquisition of
        // the year still names, so no amount drops out of the sums.
        $named = collect()
            ->merge(ProjectShare::withoutGlobalScopes()->where('organization_id', $organizationId)->pluck('employee_id'))
            ->merge(SteeringItem::withoutGlobalScopes()->where('organization_id', $organizationId)->where('year', $year)->pluck('employee_id'))
            ->merge(DB::table('budget_group_shares')
                ->join('budget_groups', 'budget_groups.id', '=', 'budget_group_shares.budget_group_id')
                ->where('budget_groups.organization_id', $organizationId)->where('budget_groups.year', $year)
                ->pluck('budget_group_shares.employee_id'))
            ->merge(array_keys($this->acquisitions->chargesByPerson($organizationId, $from, $to)))
            ->unique()->values()->all();

        $people = Employee::withoutGlobalScopes()
            ->withTrashed()
            ->where('organization_id', $organizationId)
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('is_active', true)->whereNull('deleted_at'))->orWhereIn('id', $named))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);
        $ids = $people->pluck('id')->all();
        $zero = fn () => array_fill_keys($ids, '0.00');

        // 1. Contribution of customer projects, by status
        $projects = Project::withoutGlobalScopes()->where('organization_id', $organizationId)->get();
        $byId = $projects->keyBy('id');
        $shares = ProjectShare::withoutGlobalScopes()->where('organization_id', $organizationId)->get()->groupBy('cost_center_id');
        $booked = $this->reports->totalsByProject($organizationId, $from, $to);

        $byStatus = [];
        foreach (ProjectStatus::cases() as $status) {
            $byStatus[$status->value] = $zero();
        }
        $unshared = '0.00';
        $jobs = array_fill_keys($ids, []);

        foreach ($projects as $project) {
            if ($project->kind !== ProjectKind::Project || $project->status === ProjectStatus::Lost) {
                continue;
            }
            $value = $this->valueOf($project, $booked[$project->id] ?? null, $year);
            if (Money::compare($value, '0') === 0) {
                continue;
            }
            $key = $this->keyFor($project, $byId, $shares);
            if ($key === []) {
                $unshared = Money::add($unshared, $value);

                continue;
            }
            foreach ($this->split($value, $key) as $employeeId => $part) {
                if (isset($byStatus[$project->status->value][$employeeId])) {
                    $byStatus[$project->status->value][$employeeId] = Money::add($byStatus[$project->status->value][$employeeId], $part);
                    $jobs[$employeeId][] = [
                        'id' => $project->id,
                        'code' => $project->code,
                        'name' => $project->name,
                        'group' => $this->groupOf($project, $byId),
                        'status' => $project->status->value,
                        'booked' => Money::isPositive($booked[$project->id]['revenue'] ?? '0'),
                        'share' => (string) (float) $key[$employeeId],
                        'value' => $value,
                        'part' => $part,
                    ];
                }
            }
        }

        $contribution = $zero();
        foreach ($byStatus as $amounts) {
            foreach ($amounts as $id => $amount) {
                $contribution[$id] = Money::add($contribution[$id], $amount);
            }
        }

        // 2. Effect of cost blocks: credit for work done minus the part borne
        $groups = BudgetGroup::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('year', $year)
            ->with(['people', 'accounts:id'])
            ->get();
        $budgets = Budget::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('fiscal_year', $year)
            ->pluck('monthly_amount', 'account_id');

        $credit = $zero();
        $burden = $zero();
        $blocks = [];
        foreach ($groups as $group) {
            $plan = $group->plan_amount !== null
                ? (string) $group->plan_amount
                : Money::round($group->accounts->reduce(fn (string $sum, $a) => Money::add($sum, Money::multiply((string) ($budgets[$a->id] ?? '0'), '12')), '0.00'));

            $carry = $group->people->mapWithKeys(fn (Employee $e) => [$e->id => (string) data_get($e, 'pivot.carry_share')])->filter(fn ($s) => Money::isPositive($s))->all();
            if ($carry === [] && $ids !== []) {
                // Nobody named: everyone bears an equal part, the last one
                // the remainder, so the whole plan is borne.
                $equal = bcdiv('100', (string) count($ids), 4);
                $carry = array_fill_keys($ids, $equal);
                $last = $ids[array_key_last($ids)];
                $carry[$last] = bcsub('100', bcmul($equal, (string) (count($ids) - 1), 4), 4);
            }
            $credited = $group->people->mapWithKeys(fn (Employee $e) => [$e->id => (string) data_get($e, 'pivot.credit_share')])->filter(fn ($s) => Money::isPositive($s))->all();

            $row = ['id' => $group->id, 'name' => $group->name, 'plan' => $plan, 'people' => []];
            $carryParts = $this->split($plan, $carry);
            $creditParts = $credited === [] ? [] : $this->split($plan, $credited);
            foreach ($ids as $id) {
                $c = $creditParts[$id] ?? '0.00';
                $b = $carryParts[$id] ?? '0.00';
                $credit[$id] = Money::add($credit[$id], $c);
                $burden[$id] = Money::add($burden[$id], $b);
                $row['people'][$id] = ['credit' => $c, 'burden' => Money::negate($b), 'effect' => Money::subtract($c, $b)];
            }
            $blocks[] = $row;
        }

        // 3. Drawn: plan items and acquisitions; booked salary for comparison
        $items = SteeringItem::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('year', $year)
            ->orderBy('employee_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $acquired = $this->acquisitions->chargesByPerson($organizationId, $from, $to);
        $salaries = $this->bookedSalaries($organizationId, $year);

        $rows = [];
        foreach ($people as $person) {
            $id = $person->id;
            $planned = $items->where('employee_id', $id)->reduce(fn (string $sum, SteeringItem $i) => Money::add($sum, (string) $i->amount), '0.00');
            $drawn = Money::add($planned, $acquired[$id] ?? '0.00');
            $value = Money::add($contribution[$id], Money::subtract($credit[$id], $burden[$id]));
            $play = Money::subtract($value, $drawn);

            $rows[] = [
                'employee_id' => $id,
                'name' => trim("{$person->first_name} {$person->last_name}"),
                'by_status' => array_map(fn (array $amounts) => $amounts[$id], $byStatus),
                // Spec 010: what each job brought this person, largest first.
                'jobs' => collect($jobs[$id])->sortByDesc(fn (array $job) => (float) $job['part'])->values()->all(),
                'contribution' => $contribution[$id],
                'credit' => $credit[$id],
                'burden' => Money::negate($burden[$id]),
                'effect' => Money::subtract($credit[$id], $burden[$id]),
                'value' => $value,
                'planned_items' => $planned,
                'acquisitions' => $acquired[$id] ?? '0.00',
                'drawn' => $drawn,
                'booked_salary' => $salaries[$id] ?? '0.00',
                'play' => $play,
                'play_without_possible' => Money::subtract($play, $byStatus[ProjectStatus::Possible->value][$id]),
            ];
        }

        return [
            'year' => $year,
            'people' => $rows,
            'blocks' => $blocks,
            'items' => $items->map(fn (SteeringItem $i) => [
                'id' => $i->id, 'employee_id' => $i->employee_id, 'label' => $i->label, 'amount' => (string) $i->amount,
            ])->values()->all(),
            'unshared' => $unshared,
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * Value of a customer project: the booked net once revenue is booked,
     * the expected amount less booked costs before.
     *
     * @param  array{revenue: string, expenses: string}|null  $booked
     */
    public function valueOf(Project $project, ?array $booked, int $year): string
    {
        $revenue = $booked['revenue'] ?? '0.00';
        $expenses = $booked['expenses'] ?? '0.00';

        if (Money::isPositive($revenue)) {
            return Money::subtract($revenue, $expenses);
        }

        // No revenue in this year: the expected amount only belongs to the
        // year the project is to be invoiced in (billing month, else target
        // date); an undated open project counts for the running year.
        $planned = $project->billing_month ?? $project->target_date;
        $belongs = $planned !== null
            ? (int) $planned->year === $year
            : $project->status !== ProjectStatus::Done && $year === (int) now()->year;

        return Money::subtract($belongs && $project->expected_amount !== null ? (string) $project->expected_amount : '0.00', $expenses);
    }

    /**
     * How the value of one job is shared out (spec 010, US4): its key, own or
     * inherited, and each person's part of the given value.
     *
     * @return array<int, array{employee_id: string, name: string, share: string, part: string, inherited: bool}>
     */
    public function splitOf(Project $project, string $value): array
    {
        $projects = Project::withoutGlobalScopes()->where('organization_id', $project->organization_id)->get();
        $shares = ProjectShare::withoutGlobalScopes()->where('organization_id', $project->organization_id)->get()->groupBy('cost_center_id');
        $key = $this->keyFor($project, $projects->keyBy('id'), $shares);
        if ($key === []) {
            return [];
        }

        $inherited = ($shares->get($project->id) ?? collect())->isEmpty();
        $names = Employee::withoutGlobalScopes()->withTrashed()->whereIn('id', array_keys($key))->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return collect($this->split($value, $key))
            ->map(fn (string $part, string $employeeId) => [
                'employee_id' => $employeeId,
                'name' => trim(($names[$employeeId]->first_name ?? '').' '.($names[$employeeId]->last_name ?? '')),
                'share' => (string) (float) $key[$employeeId],
                'part' => $part,
                'inherited' => $inherited,
            ])
            ->sortByDesc(fn (array $row) => (float) $row['share'])
            ->values()
            ->all();
    }

    /**
     * Name of the group (top-most area) a job belongs to, if any.
     *
     * @param  Collection<int, Project>  $byId
     */
    private function groupOf(Project $project, Collection $byId): ?string
    {
        $group = null;
        $cursor = $project->parent_id !== null ? $byId->get($project->parent_id) : null;
        for ($depth = 0; $cursor !== null && $depth < 32; $depth++) {
            if ($cursor->kind === ProjectKind::Area) {
                $group = $cursor->name;
            }
            $cursor = $cursor->parent_id !== null ? $byId->get($cursor->parent_id) : null;
        }

        return $group;
    }

    /**
     * The key of a project, or of the nearest project above it with one.
     *
     * @param  Collection<int, Project>  $byId
     * @param  Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, ProjectShare>>  $shares
     * @return array<string, string> employee id => percent
     */
    private function keyFor(Project $project, Collection $byId, Collection $shares): array
    {
        $cursor = $project;
        for ($depth = 0; $cursor !== null && $depth < 32; $depth++) {
            $own = $shares->get($cursor->id);
            if ($own !== null && $own->isNotEmpty()) {
                return $own->mapWithKeys(fn (ProjectShare $s) => [$s->employee_id => (string) $s->share])->all();
            }
            $cursor = $cursor->parent_id !== null ? $byId->get($cursor->parent_id) : null;
        }

        return [];
    }

    /**
     * @param  array<string, string>  $key  employee id => percent
     * @return array<string, string>
     */
    private function split(string $amount, array $key): array
    {
        ksort($key);
        $parts = LineAllocationService::splitAmount($amount, array_values($key));

        return array_combine(array_keys($key), $parts);
    }

    /**
     * What each person's booked salary slips of the year cost the company:
     * gross, employer contributions and reimbursed allowances.
     *
     * @return array<string, string>
     */
    private function bookedSalaries(string $organizationId, int $year): array
    {
        $totals = [];
        $slips = SalarySlip::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('period_year', $year)
            // Only slips that count: posted here, or booked elsewhere.
            ->where(fn ($q) => $q->whereNotNull('posted_at')->orWhere('booked_externally', true))
            ->get(['employee_id', 'gross_salary', 'deductions', 'adjustments']);

        foreach ($slips as $slip) {
            $cost = (string) $slip->gross_salary;
            foreach ((array) $slip->deductions as $code => $amount) {
                // Each employer contribution once; total_employer sums them.
                if (str_ends_with((string) $code, '_employer') && ! str_starts_with((string) $code, 'total_') && is_numeric($amount)) {
                    $cost = Money::add($cost, (string) $amount);
                }
            }
            $cost = Money::add($cost, (string) ($slip->adjustments['reimbursement_amount'] ?? '0'));
            $totals[$slip->employee_id] = Money::add($totals[$slip->employee_id] ?? '0.00', $cost);
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function totals(array $rows): array
    {
        $fields = ['contribution', 'credit', 'burden', 'effect', 'value', 'planned_items', 'acquisitions', 'drawn', 'booked_salary', 'play', 'play_without_possible'];
        $totals = array_fill_keys($fields, '0.00');
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $totals[$field] = Money::add($totals[$field], (string) $row[$field]);
            }
        }

        return $totals;
    }
}
