<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Requests\BudgetGroupRequest;
use App\Domains\Projects\Services\PlanActualService;
use App\Domains\Projects\Services\ProjectJournalService;
use App\Http\Controllers\Concerns\AnswersApiCalls;
use App\Http\Controllers\Controller;
use App\Support\CsvExportService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Plan against actual per cost block (spec 009, User Story 10).
 */
class BudgetGroupController extends Controller
{
    use AnswersApiCalls;

    /**
     * Spec 010: the cost blocks live on the jobs page now; the old address
     * keeps working.
     */
    public function index(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Project::class);

        return redirect()->to(route('projects.index', ['year' => $this->year($request)]).'#general-costs');
    }

    public function store(BudgetGroupRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $currentOrg): void {
            $group = BudgetGroup::create([
                'organization_id' => $currentOrg->id(),
                'year' => $validated['year'],
                'name' => $validated['name'],
                'kind' => $validated['kind'],
                'plan_amount' => $validated['plan_amount'] ?? null,
                'position' => $validated['position'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);
            $group->accounts()->sync($validated['account_ids'] ?? []);
            $this->syncPeople($group, $validated);
        });

        return back()->with('success', __('app.saved'));
    }

    public function update(BudgetGroupRequest $request, BudgetGroup $budgetGroup): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $budgetGroup): void {
            $budgetGroup->update([
                'name' => $validated['name'],
                'kind' => $validated['kind'],
                'plan_amount' => $validated['plan_amount'] ?? null,
                'position' => $validated['position'] ?? $budgetGroup->position,
                'notes' => $validated['notes'] ?? null,
            ]);
            $budgetGroup->accounts()->sync($validated['account_ids'] ?? []);
            $this->syncPeople($budgetGroup, $validated);
        });

        return $this->answer(__('app.saved'), ['id' => $budgetGroup->id]);
    }

    /**
     * The booked lines behind a block's actual, for unfolding its row on the
     * jobs page (spec 010). Lines that sit with a customer job are marked,
     * as the actual leaves them out.
     */
    public function lines(BudgetGroup $budgetGroup, ProjectJournalService $journal, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $accountIds = $budgetGroup->accounts()->pluck('accounts.id')->map(fn ($id) => (int) $id)->flip();
        $projects = Project::query()->get(['id', 'name', 'kind'])->keyBy('id');

        $rows = collect($journal->forYear($currentOrg->id(), (int) $budgetGroup->year))
            ->filter(fn (array $row) => $accountIds->has($row['account']['id']))
            ->sortBy([['date', 'desc'], ['line_id', 'desc']])
            ->map(function (array $row) use ($projects): array {
                /** @var array<int, array{project_id: int|null, share: numeric-string}> $allocations */
                $allocations = $row['allocations'];
                $jobs = [];
                $atJobs = '0';
                foreach ($allocations as $allocation) {
                    $project = $allocation['project_id'] !== null ? $projects->get($allocation['project_id']) : null;
                    if ($project !== null && $project->kind === ProjectKind::Project) {
                        $jobs[$project->id] = $project->name;
                        $atJobs = bcadd($atJobs, $allocation['share'], 4);
                    }
                }
                $amount = Money::negate($row['net']);

                return [
                    'line_id' => $row['line_id'],
                    'entry_id' => $row['entry_id'],
                    'date' => $row['date'],
                    'reference' => $row['reference'],
                    'document_url' => $row['document_url'],
                    'description' => $row['description'],
                    'account' => $row['account']['code'],
                    'counter' => $row['debit_account'] === $row['account']['code'] ? $row['credit_account'] : $row['debit_account'],
                    // Cost positive, as the block's actual counts it; the part
                    // at customer jobs is not in the actual.
                    'amount' => $amount,
                    'at_jobs' => Money::round(bcdiv(bcmul($amount, $atJobs, 6), '100', 6)),
                    'jobs' => array_values($jobs),
                ];
            })
            ->values();

        return response()->json(['rows' => $rows]);
    }

    public function destroy(BudgetGroup $budgetGroup): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Project::class);

        $budgetGroup->delete();

        return $this->answer(__('app.deleted'));
    }

    public function copyPrevious(Request $request, PlanActualService $plans, CurrentOrganization $currentOrg): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Project::class);

        $count = $plans->copyFromPreviousYear($currentOrg->id(), $this->year($request));

        return $this->answer(__('app.budget_groups_copied', ['count' => $count]), ['copied' => $count]);
    }

    public function export(Request $request, PlanActualService $plans, CurrentOrganization $currentOrg, CsvExportService $csv): HttpResponse
    {
        $this->authorize('viewAny', Project::class);

        $year = $this->year($request);
        $data = $plans->forYear($currentOrg->id(), $year);

        $rows = array_map(fn (array $g) => [
            $g['name'],
            __('app.budget_group_kind_'.$g['kind']),
            implode(', ', array_column($g['accounts'], 'code')),
            $g['plan'],
            $g['actual'],
            $g['customer_projects'],
            $g['rest'],
            $g['projection'],
        ], $data['groups']);

        return $csv->export([
            __('app.name'), __('app.budget_group_kind'), __('app.budget_group_accounts'), __('app.budget_group_plan'),
            __('app.budget_group_actual'), __('app.budget_group_customer_projects'), __('app.budget_group_rest'), __('app.budget_group_projection'),
        ], $rows, "plan-actual-{$year}.csv");
    }

    /**
     * Owners set the carry and credit keys of a block (spec 009, stage 5);
     * the field is ignored for everyone else.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncPeople(BudgetGroup $group, array $validated): void
    {
        if (! array_key_exists('people', $validated) || ! (request()->user()?->can('steer', Project::class) ?? false)) {
            return;
        }

        $group->people()->sync($this->keys((array) $validated['people']));
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }

    /**
     * @param  array<int, array{employee_id: string, carry_share: mixed, credit_share: mixed}>  $people
     * @return array<string, array{carry_share: string, credit_share: string}>
     */
    private function keys(array $people): array
    {
        $keys = [];
        foreach ($people as $p) {
            $keys[$p['employee_id']] = ['carry_share' => (string) $p['carry_share'], 'credit_share' => (string) $p['credit_share']];
        }

        return $keys;
    }
}
