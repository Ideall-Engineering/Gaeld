<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Models\Budget;
use App\Domains\Expenses\Services\RecurringExpensePlan;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectShare;
use App\Domains\Projects\Models\SteeringItem;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contribution and drawings per person, and what is left to play with
 * (spec 009, stage 5).
 *
 * Contribution: each customer project's value shared by its key (its own,
 * or the nearest one above it). Only jobs in progress or done count; open
 * and quoted jobs, and amounts expected after the year, are shown beside it
 * but never planned with. The value is the booked net once revenue
 * is booked, before that the expected amount less booked costs.
 * Effect of cost blocks: who did the work is credited the planned amount,
 * everyone bears their part by the carry key. Drawn: the planned drawings
 * plus acquisitions; booked salary is shown beside it for comparison.
 */
class SteeringService
{
    /** Job statuses whose value is planned with. */
    private const COUNTED = [ProjectStatus::InProgress, ProjectStatus::Done];

    public function __construct(
        private ProjectReportService $reports,
        private AcquisitionService $acquisitions,
        private RecurringExpensePlan $recurring,
        private ExpectedExpensesService $expected,
    ) {}

    /**
     * With `$withNextYear` the result also carries the open payments of the
     * following year, shown in their own tab on the people page.
     *
     * @return array<string, mixed>
     */
    public function forYear(string $organizationId, int $year, bool $withNextYear = true): array
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

        // The owners: everyone named in a job's key. What no key names
        // (a cost block without carry key, general costs) they bear in equal
        // parts; other staff on the payroll bear nothing.
        $keyed = ProjectShare::withoutGlobalScopes()->where('organization_id', $organizationId)->pluck('employee_id')->unique()->all();
        /** @var list<string> $owners */
        $owners = array_values(array_filter($ids, fn (string $id) => in_array($id, $keyed, true))) ?: array_values($ids);
        $ownersKey = self::equalKey($owners);

        // 1. Contribution of customer projects, by status
        $projects = Project::withoutGlobalScopes()->where('organization_id', $organizationId)->get();
        $byId = $projects->keyBy('id');
        $shares = ProjectShare::withoutGlobalScopes()->where('organization_id', $organizationId)->get()->groupBy('cost_center_id');
        $booked = $this->reports->totalsByProject($organizationId, $from, $to);
        // Revenue ever booked per job: what is invoiced in any year comes off
        // the expected amount (question 2, 2026-10-21).
        $ever = $this->reports->totalsByProject($organizationId, '1900-01-01', '2999-12-31');

        $byStatus = [];
        foreach (ProjectStatus::cases() as $status) {
            $byStatus[$status->value] = $zero();
        }
        $unshared = '0.00';
        $unsharedJobs = [];
        $jobs = array_fill_keys($ids, []);
        $prospectJobs = array_fill_keys($ids, []);
        $laterJobs = array_fill_keys($ids, []);
        $later = $zero();
        $general = $zero();
        $openPayments = [];
        $openByJob = $this->openByJob($organizationId);

        foreach ($projects as $project) {
            // Projects, internal jobs ("Allgemein") and lost jobs count with
            // what is booked on them, by their key or else by the owners.
            if ($project->kind !== ProjectKind::Project || $project->status === ProjectStatus::Lost) {
                $net = Money::subtract($booked[$project->id]['revenue'] ?? '0.00', $booked[$project->id]['expenses'] ?? '0.00');
                if (Money::compare($net, '0') === 0) {
                    continue;
                }
                $key = $this->keyFor($project, $byId, $shares) ?: $ownersKey;
                if ($key === []) {
                    $unshared = Money::add($unshared, $net);
                    $unsharedJobs[] = ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'group' => $this->groupOf($project, $byId), 'amount' => $net];

                    continue;
                }
                foreach ($this->split($net, $key) as $employeeId => $part) {
                    if (isset($general[$employeeId])) {
                        $general[$employeeId] = Money::add($general[$employeeId], $part);
                        $jobs[$employeeId][] = [
                            'id' => $project->id,
                            'code' => $project->code,
                            'name' => $project->name,
                            'group' => $this->groupOf($project, $byId),
                            'status' => $project->status->value,
                            'booked' => true,
                            'general' => true,
                            'billing' => null,
                            'share' => (string) (float) $key[$employeeId],
                            'value' => $net,
                            'part' => $part,
                            'open_part' => '0.00',
                        ];
                    }
                }

                continue;
            }
            $everRevenue = $ever[$project->id]['revenue'] ?? '0.00';
            $rest = $this->restOf($project, $everRevenue);
            $restInYear = $this->expectsInYear($project, $year) ? $rest : '0.00';
            $value = $this->valueOf($project, $booked[$project->id] ?? null, $year, $everRevenue);
            $expectedLater = $this->expectedLater($project, $rest, $year);
            if (Money::compare($value, '0') === 0 && $expectedLater === null) {
                continue;
            }
            $counted = in_array($project->status, self::COUNTED, true);
            $key = $this->keyFor($project, $byId, $shares);
            $isBooked = Money::isPositive($booked[$project->id]['revenue'] ?? '0');

            // Still to come in this year: counted, part of it not invoiced yet.
            if ($counted && Money::isPositive($restInYear)) {
                $openPayments[] = [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'group' => $this->groupOf($project, $byId),
                    'status' => $project->status->value,
                    'billing' => $this->plannedOn($project)?->format('Y-m'),
                    'payment_expected_on' => $project->payment_expected_on?->toDateString(),
                    'billing_month' => $project->billing_month?->format('Y-m'),
                    'sort' => $this->plannedOn($project)?->toDateString(),
                    'expected' => $restInYear,
                    'expected_total' => (string) $project->expected_amount,
                    'invoiced' => $everRevenue,
                    'costs' => $booked[$project->id]['expenses'] ?? '0.00',
                    'value' => $value,
                    'people' => $key === [] ? [] : $this->split($value, $key),
                ];
            }
            if ($key === []) {
                if ($counted) {
                    $unshared = Money::add($unshared, $value);
                    $unsharedJobs[] = ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'group' => $this->groupOf($project, $byId), 'amount' => $value];
                }

                continue;
            }
            $openParts = isset($openByJob[$project->id]) ? $this->split($openByJob[$project->id], $key) : [];
            $restParts = Money::isPositive($restInYear) ? $this->split($restInYear, $key) : [];
            $entry = fn (string $employeeId, string $amount, string $part) => [
                'open_part' => $openParts[$employeeId] ?? '0.00',
                'rest_part' => $restParts[$employeeId] ?? '0.00',
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'group' => $this->groupOf($project, $byId),
                'status' => $project->status->value,
                'booked' => $isBooked,
                'billing' => $this->plannedOn($project)?->format('Y-m'),
                'share' => (string) (float) $key[$employeeId],
                'value' => $amount,
                'part' => $part,
            ];
            if (Money::compare($value, '0') !== 0) {
                foreach ($this->split($value, $key) as $employeeId => $part) {
                    if (isset($byStatus[$project->status->value][$employeeId])) {
                        $byStatus[$project->status->value][$employeeId] = Money::add($byStatus[$project->status->value][$employeeId], $part);
                        if ($counted) {
                            $jobs[$employeeId][] = $entry($employeeId, $value, $part);
                        } else {
                            $prospectJobs[$employeeId][] = $entry($employeeId, $value, $part);
                        }
                    }
                }
            }
            // Expected in a later year: visible, not planned with.
            if ($expectedLater !== null) {
                foreach ($this->split($expectedLater, $key) as $employeeId => $part) {
                    if (isset($later[$employeeId])) {
                        $later[$employeeId] = Money::add($later[$employeeId], $part);
                        $laterJobs[$employeeId][] = $entry($employeeId, $expectedLater, $part);
                    }
                }
            }
        }

        // Only what is being done or done is planned with, plus what is
        // booked on projects, internal and lost jobs.
        $contribution = $general;
        $prospects = $zero();
        foreach ($byStatus as $status => $amounts) {
            $counted = in_array(ProjectStatus::from($status), self::COUNTED, true);
            foreach ($amounts as $id => $amount) {
                if ($counted) {
                    $contribution[$id] = Money::add($contribution[$id], $amount);
                } else {
                    $prospects[$id] = Money::add($prospects[$id], $amount);
                }
            }
        }

        // 2. Effect of cost blocks: credit for work done minus the part borne
        $groups = BudgetGroup::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('year', $year)
            ->with(['people', 'accounts:id,code,name'])
            ->get();
        $budgets = Budget::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('fiscal_year', $year)
            ->pluck('monthly_amount', 'account_id');
        $recurring = $this->recurring->forYear($organizationId, $year);

        $credit = $zero();
        $burden = $zero();
        $blocks = [];
        foreach ($groups as $group) {
            // How the plan comes about, shown when a block is opened: a set
            // amount, or per account twelve times the monthly budget plus
            // the recurring expenses due in the year.
            $sources = $group->accounts->sortBy('code')->map(function ($a) use ($budgets, $recurring) {
                $budgetYearly = Money::round(Money::multiply((string) ($budgets[$a->id] ?? '0'), '12'));
                $items = $recurring[$a->id] ?? [];

                return [
                    'code' => $a->code,
                    'name' => $a->name,
                    'monthly' => (string) ($budgets[$a->id] ?? '0.00'),
                    'budget_yearly' => $budgetYearly,
                    'recurring' => $items,
                    'yearly' => Money::add($budgetYearly, RecurringExpensePlan::total($items)),
                ];
            })->values()->all();
            $plan = $group->plan_amount !== null
                ? (string) $group->plan_amount
                : Money::round(array_reduce($sources, fn (string $sum, array $s) => Money::add($sum, $s['yearly']), '0.00'));

            $carry = $group->people->mapWithKeys(fn (Employee $e) => [$e->id => (string) data_get($e, 'pivot.carry_share')])->filter(fn ($s) => Money::isPositive($s))->all();
            $carryEqual = $carry === [];
            if ($carry === []) {
                // Nobody named: the owners bear equal parts.
                $carry = $ownersKey;
            }
            $credited = $group->people->mapWithKeys(fn (Employee $e) => [$e->id => (string) data_get($e, 'pivot.credit_share')])->filter(fn ($s) => Money::isPositive($s))->all();

            $row = [
                'id' => $group->id,
                'name' => $group->name,
                'plan' => $plan,
                'plan_fixed' => $group->plan_amount !== null,
                'accounts' => $sources,
                'carry_equal' => $carryEqual,
                'people' => [],
            ];
            $carryParts = $this->split($plan, $carry);
            $creditParts = $credited === [] ? [] : $this->split($plan, $credited);
            foreach ($ids as $id) {
                $c = $creditParts[$id] ?? '0.00';
                $b = $carryParts[$id] ?? '0.00';
                $credit[$id] = Money::add($credit[$id], $c);
                $burden[$id] = Money::add($burden[$id], $b);
                $row['people'][$id] = [
                    'carry_share' => isset($carry[$id]) ? (string) (float) $carry[$id] : '0',
                    'credit_share' => isset($credited[$id]) ? (string) (float) $credited[$id] : '0',
                    'credit' => $c,
                    'burden' => Money::negate($b),
                    'effect' => Money::subtract($c, $b),
                ];
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

        // Spec 012: the expenses still expected, beside the payments; not
        // worked out for the inner call that only fetches next year's.
        $expected = $withNextYear ? $this->expected->forYear($organizationId, $year) : null;

        $rows = [];
        foreach ($people as $person) {
            $id = $person->id;
            // Received is paid: booked, less what open invoices still owe.
            $received = array_reduce($jobs[$id], fn (string $sum, array $job) => $job['booked'] ? Money::add($sum, Money::subtract(Money::subtract($job['part'], $job['open_part']), $job['rest_part'] ?? '0.00')) : $sum, '0.00');
            $planned = $items->where('employee_id', $id)->reduce(fn (string $sum, SteeringItem $i) => Money::add($sum, (string) $i->amount), '0.00');
            // The plan items hold the salary of the whole year (fixed salary,
            // 13th, employer contributions, allowance): nothing is added from
            // the expected salaries, or it would be drawn twice.
            $drawn = Money::add($planned, $acquired[$id] ?? '0.00');
            $value = Money::add($contribution[$id], Money::subtract($credit[$id], $burden[$id]));
            $play = Money::subtract($value, $drawn);

            $rows[] = [
                'employee_id' => $id,
                'name' => trim("{$person->first_name} {$person->last_name}"),
                'by_status' => array_map(fn (array $amounts) => $amounts[$id], $byStatus),
                // Spec 010: what each job brought this person, largest first.
                'jobs' => collect($jobs[$id])->sortByDesc(fn (array $job) => (float) $job['part'])->values()->all(),
                'prospect_jobs' => collect($prospectJobs[$id])->sortByDesc(fn (array $job) => (float) $job['part'])->values()->all(),
                'later_jobs' => collect($laterJobs[$id])->sortByDesc(fn (array $job) => (float) $job['part'])->values()->all(),
                'contribution' => $contribution[$id],
                // The contribution split: received (revenue booked) and still
                // expected this year (expected amount less booked costs).
                'contribution_received' => $received,
                'contribution_expected' => Money::subtract($contribution[$id], $received),
                'credit' => $credit[$id],
                'burden' => Money::negate($burden[$id]),
                'effect' => Money::subtract($credit[$id], $burden[$id]),
                'value' => $value,
                'planned_items' => $planned,
                'acquisitions' => $acquired[$id] ?? '0.00',
                'general' => $general[$id],
                'drawn' => $drawn,
                'booked_salary' => $salaries[$id] ?? '0.00',
                'play' => $play,
                // Shown, not counted: open and quoted jobs, and later years.
                'prospects' => $prospects[$id],
                'later' => $later[$id],
            ];
        }

        $open = $this->withInvoices(
            $organizationId,
            collect($openPayments)->sortBy(fn (array $p) => $p['sort'] ?? '9999')->values()->all(),
            array_column($rows, 'name', 'employee_id'),
        );
        $openNext = $withNextYear ? $this->forYear($organizationId, $year + 1, false)['open_payments'] : [];

        // For the running year the outlook month by month, with the open
        // invoices coming in on their due day.
        $expectedNext = $withNextYear ? $this->expected->forYear($organizationId, $year + 1) : null;
        $openInvoices = $withNextYear ? $this->openInvoices($organizationId, $byId) : [];
        $outlook = $expected !== null && $expectedNext !== null && $year === (int) now()->year
            ? $this->expected->outlook($organizationId, [$year => $open, $year + 1 => $openNext], [$year => $expected, $year + 1 => $expectedNext], $openInvoices)
            : null;

        return [
            'year' => $year,
            'people' => $rows,
            'blocks' => $blocks,
            'items' => $items->map(fn (SteeringItem $i) => [
                'id' => $i->id, 'employee_id' => $i->employee_id, 'label' => $i->label, 'amount' => (string) $i->amount,
            ])->values()->all(),
            'unshared' => $unshared,
            'unshared_jobs' => $unsharedJobs,
            'open_payments' => $open,
            'open_payments_next_year' => $openNext,
            'open_invoices' => $this->namedInvoices($openInvoices, $byId, $shares, array_column($rows, 'name', 'employee_id')),
            'expected_expenses' => $expected,
            'expected_expenses_next_year' => $expectedNext,
            'outlook' => $outlook,
            'done_without_revenue' => $this->doneWithoutRevenue($organizationId, $projects, $byId),
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * Value of a customer project in a year: what is booked in the year,
     * plus the rest still expected when it is expected in that year.
     *
     * @param  array{revenue: string, expenses: string}|null  $booked
     * @param  string|null  $everRevenue  revenue booked on the job in any year; read when not given
     */
    public function valueOf(Project $project, ?array $booked, int $year, ?string $everRevenue = null): string
    {
        $net = Money::subtract($booked['revenue'] ?? '0.00', $booked['expenses'] ?? '0.00');
        if (! $this->expectsInYear($project, $year)) {
            return $net;
        }
        $everRevenue ??= $this->reports->totalsByProject($project->organization_id, '1900-01-01', '2999-12-31')[$project->id]['revenue'] ?? '0.00';

        return Money::add($net, $this->restOf($project, $everRevenue));
    }

    /**
     * What a job still brings beyond everything invoiced on it in any year:
     * the expected amount less the revenue ever booked, never below zero. A
     * job done and invoiced is final; one done without any revenue still
     * waits for its invoice.
     */
    public function restOf(Project $project, string $everRevenue): string
    {
        if ($project->expected_amount === null
            || ($project->status === ProjectStatus::Done && Money::isPositive($everRevenue))) {
            return '0.00';
        }
        $rest = Money::subtract((string) $project->expected_amount, $everRevenue);

        return Money::isPositive($rest) ? $rest : '0.00';
    }

    /**
     * When a job's money is planned to come in: the day its payment is
     * expected, else its billing month, else its target date.
     */
    private function plannedOn(Project $project): ?Carbon
    {
        return $project->payment_expected_on ?? $project->billing_month ?? $project->target_date;
    }

    /**
     * Whether a job's expected amount belongs to the year: the year it is to
     * be invoiced in (billing month, else target date); an undated job not
     * yet done counts for the running year.
     */
    private function expectsInYear(Project $project, int $year): bool
    {
        if ($project->expected_amount === null) {
            return false;
        }
        $planned = $this->plannedOn($project);

        return $planned !== null
            ? (int) $planned->year === $year
            : $project->status !== ProjectStatus::Done && $year === (int) now()->year;
    }

    /**
     * Names the people of each open payment and adds the invoices linked to
     * the job, so one sees whether it is billed already.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<string, string>  $names
     * @return array<int, array<string, mixed>>
     */
    private function withInvoices(string $organizationId, array $payments, array $names): array
    {
        $invoices = InvoiceProject::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('cost_center_id', array_column($payments, 'id'))
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', InvoiceStatus::Cancelled->value))
            ->with('invoice')
            ->get()
            ->groupBy('cost_center_id');

        $named = function (array $parts) use ($names): array {
            $people = [];
            foreach ($parts as $id => $part) {
                $people[] = ['name' => $names[$id] ?? '', 'part' => (string) $part];
            }

            return $people;
        };

        return array_map(fn (array $payment) => [
            ...$payment,
            'people' => $named((array) $payment['people']),
            'invoices' => ($invoices->get($payment['id']) ?? collect())
                ->map(fn (InvoiceProject $link) => [
                    'id' => $link->invoice->id,
                    'number' => $link->invoice->number,
                    'status' => $link->invoice->status->value,
                    'total' => (string) $link->invoice->total,
                ])->values()->all(),
        ], $payments);
    }

    /**
     * Jobs marked done without any revenue ever booked on them: either the
     * payment is missing or it was booked without the job.
     *
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Project>  $byId
     * @return array<int, array<string, mixed>>
     */
    private function doneWithoutRevenue(string $organizationId, Collection $projects, Collection $byId): array
    {
        $done = $projects->filter(fn (Project $p) => $p->kind === ProjectKind::Project && $p->status === ProjectStatus::Done);
        if ($done->isEmpty()) {
            return [];
        }
        $ever = $this->reports->totalsByProject($organizationId, '1900-01-01', '2999-12-31');

        return $done
            ->filter(fn (Project $p) => ! Money::isPositive($ever[$p->id]['revenue'] ?? '0'))
            ->sortBy('code')
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'group' => $this->groupOf($p, $byId),
                'expected' => $p->expected_amount !== null ? (string) $p->expected_amount : null,
                'billing' => $this->plannedOn($p)?->format('Y-m'),
            ])->values()->all();
    }

    /**
     * The rest of a job that is expected after the given year; null when
     * there is none.
     */
    private function expectedLater(Project $project, string $rest, int $year): ?string
    {
        $planned = $this->plannedOn($project);
        if ($planned === null || (int) $planned->year <= $year || ! Money::isPositive($rest)) {
            return null;
        }

        return $rest;
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
     * Equal parts in percent for the given people, the last one taking the
     * remainder so the parts add up to 100.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private static function equalKey(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $equal = bcdiv('100', (string) count($ids), 4);
        $key = array_fill_keys($ids, $equal);
        $key[$ids[array_key_last($ids)]] = bcsub('100', bcmul($equal, (string) (count($ids) - 1), 4), 4);

        return $key;
    }

    /**
     * Invoices sent and not yet paid in full: what each still owes, with
     * VAT (gross) and without (net), less payments and credit notes.
     *
     * @param  Collection<int, Project>  $byId
     * @return list<array<string, mixed>>
     */
    public function openInvoices(string $organizationId, Collection $byId): array
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('type', InvoiceType::Invoice->value)
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Overdue->value])
            ->withSum('payments', 'amount')
            ->with('customer:id,name')
            ->orderBy('due_date')
            ->get();
        if ($invoices->isEmpty()) {
            return [];
        }

        $credited = Invoice::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('type', InvoiceType::CreditNote->value)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->whereIn('related_invoice_id', $invoices->pluck('id'))
            ->get(['related_invoice_id', 'total'])
            ->groupBy('related_invoice_id')
            ->map(fn ($notes) => $notes->reduce(fn (string $sum, Invoice $note) => Money::add($sum, Money::absoluteAmount((string) $note->total)), '0.00'));
        $jobs = InvoiceProject::withoutGlobalScopes()->whereIn('invoice_id', $invoices->pluck('id'))->pluck('cost_center_id', 'invoice_id');

        $open = [];
        foreach ($invoices as $invoice) {
            $gross = Money::subtract(Money::subtract((string) $invoice->total, $invoice->amountPaid()), (string) ($credited[$invoice->id] ?? '0.00'));
            if (! Money::isPositive($gross)) {
                continue;
            }
            $total = (string) $invoice->total;
            $net = Money::isPositive($total)
                ? Money::round(bcdiv(bcmul($gross, Money::of((string) $invoice->subtotal), 8), $total, 8))
                : $gross;
            $jobId = isset($jobs[$invoice->id]) ? (int) $jobs[$invoice->id] : null;
            $job = $jobId !== null ? $byId->get($jobId) : null;
            $open[] = [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'customer' => $invoice->customer?->name,
                'due_date' => $invoice->due_date?->toDateString(),
                'gross' => $gross,
                'net' => $net,
                'job_id' => $jobId,
                'job' => $job !== null ? "{$job->code} {$job->name}" : null,
            ];
        }

        return $open;
    }

    /**
     * Open invoices with the job they belong to and each person's part of
     * what they still owe (net), as the open payments show them.
     *
     * @param  list<array<string, mixed>>  $invoices
     * @param  Collection<int, Project>  $byId
     * @param  Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, ProjectShare>>  $shares
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function namedInvoices(array $invoices, Collection $byId, Collection $shares, array $names): array
    {
        return array_map(function (array $invoice) use ($byId, $shares, $names): array {
            $job = $invoice['job_id'] !== null ? $byId->get($invoice['job_id']) : null;
            $key = $job !== null ? $this->keyFor($job, $byId, $shares) : [];
            $people = [];
            foreach ($key === [] ? [] : $this->split((string) $invoice['net'], $key) as $id => $part) {
                $people[] = ['name' => $names[$id] ?? '', 'part' => $part];
            }

            return [
                ...$invoice,
                'job_code' => $job?->code,
                'job_name' => $job?->name,
                'group' => $job !== null ? $this->groupOf($job, $byId) : null,
                'people' => $people,
            ];
        }, $invoices);
    }

    /**
     * What open invoices still owe per job, net.
     *
     * @return array<int, string> job id => net amount
     */
    private function openByJob(string $organizationId): array
    {
        $byJob = [];
        foreach ($this->openInvoices($organizationId, collect()) as $invoice) {
            if ($invoice['job_id'] !== null) {
                $byJob[$invoice['job_id']] = Money::add($byJob[$invoice['job_id']] ?? '0.00', $invoice['net']);
            }
        }

        return $byJob;
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
            $cost = ExpectedExpensesService::slipCost((string) $slip->gross_salary, (array) $slip->deductions, (array) $slip->adjustments);
            $totals[$slip->employee_id] = Money::add($totals[$slip->employee_id] ?? '0.00', Money::add($cost['salary'], $cost['employer']));
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function totals(array $rows): array
    {
        $fields = ['contribution', 'general', 'credit', 'burden', 'effect', 'value', 'planned_items', 'acquisitions', 'drawn', 'booked_salary', 'play', 'prospects', 'later'];
        $totals = array_fill_keys($fields, '0.00');
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $totals[$field] = Money::add($totals[$field], (string) $row[$field]);
            }
        }

        return $totals;
    }
}
