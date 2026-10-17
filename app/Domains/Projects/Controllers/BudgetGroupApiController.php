<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\PlanActualService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * JSON API access to cost blocks and their plan against actual (spec 009,
 * stage 3).
 */
class BudgetGroupApiController extends Controller
{
    /**
     * Plan against actual
     *
     * The cost blocks of a year with plan, actual, rest and projection.
     */
    public function index(Request $request, PlanActualService $plans, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $year = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];

        return response()->json(['data' => $plans->forYear($currentOrg->id(), (int) $year)]);
    }

    /**
     * Create cost block
     *
     * Accounts are named by code; an account already in another block of
     * the year is refused.
     */
    public function store(Request $request, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('create', Project::class);

        $orgId = $currentOrg->id();
        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'name' => ['required', 'string', 'max:120', Rule::unique('budget_groups', 'name')->where('organization_id', $orgId)->where('year', (int) $request->input('year'))],
            'kind' => ['sometimes', Rule::in(BudgetGroupKind::values())],
            'plan_amount' => ['nullable', 'string', 'regex:/^\d{1,11}(?:\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'account_codes' => ['array', 'max:100'],
            'account_codes.*' => ['string', Rule::exists('accounts', 'code')->where('organization_id', $orgId)],
        ]);

        $accountIds = Account::query()->whereIn('code', $validated['account_codes'] ?? [])->pluck('id')->all();
        $taken = DB::table('budget_group_accounts')
            ->join('budget_groups', 'budget_groups.id', '=', 'budget_group_accounts.budget_group_id')
            ->where('budget_groups.organization_id', $orgId)
            ->where('budget_groups.year', (int) $validated['year'])
            ->whereIn('budget_group_accounts.account_id', $accountIds)
            ->exists();

        if ($taken) {
            return response()->json(['message' => 'An account is already part of another cost block of this year.', 'code' => 'account_taken'], 422);
        }

        $group = DB::transaction(function () use ($validated, $orgId, $accountIds): BudgetGroup {
            $group = BudgetGroup::create([
                'organization_id' => $orgId,
                'year' => (int) $validated['year'],
                'name' => $validated['name'],
                'kind' => $validated['kind'] ?? BudgetGroupKind::Running->value,
                'plan_amount' => $validated['plan_amount'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
            $group->accounts()->sync($accountIds);

            return $group;
        });

        return response()->json(['data' => [
            'id' => $group->id,
            'year' => $group->year,
            'name' => $group->name,
            'kind' => $group->kind->value,
            'plan_amount' => $group->plan_amount,
            'account_codes' => $group->accounts()->orderBy('code')->pluck('code')->all(),
        ]], 201);
    }
}
