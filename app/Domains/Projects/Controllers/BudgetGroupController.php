<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Requests\BudgetGroupRequest;
use App\Domains\Projects\Services\PlanActualService;
use App\Http\Controllers\Controller;
use App\Support\CsvExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Plan against actual per cost block (spec 009, User Story 10).
 */
class BudgetGroupController extends Controller
{
    public function index(Request $request, PlanActualService $plans, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', Project::class);

        $year = $this->year($request);

        return Inertia::render('Projects/Plan', [
            'year' => $year,
            'plan' => $plans->forYear($currentOrg->id(), $year),
            'accounts' => Account::query()
                ->where('type', AccountType::Expense->value)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->display_name]),
            'kinds' => array_map(fn (BudgetGroupKind $k) => ['value' => $k->value, 'label' => __('app.budget_group_kind_'.$k->value)], BudgetGroupKind::cases()),
            'hasPreviousYear' => BudgetGroup::query()->where('year', $year - 1)->exists(),
            'can' => ['manage' => $request->user()?->can('create', Project::class) ?? false],
        ]);
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
        });

        return back()->with('success', __('app.saved'));
    }

    public function update(BudgetGroupRequest $request, BudgetGroup $budgetGroup): RedirectResponse
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
        });

        return back()->with('success', __('app.saved'));
    }

    public function destroy(BudgetGroup $budgetGroup): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $budgetGroup->delete();

        return back()->with('success', __('app.deleted'));
    }

    public function copyPrevious(Request $request, PlanActualService $plans, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $count = $plans->copyFromPreviousYear($currentOrg->id(), $this->year($request));

        return back()->with('success', __('app.budget_groups_copied', ['count' => $count]));
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

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }
}
