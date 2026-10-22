<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\InternalSettlement;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Concerns\AnswersApiCalls;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Internal settlements on the general costs page (2026-10-22), changed by
 * owners like the rest of the steering. Over the API as well.
 */
class InternalSettlementController extends Controller
{
    use AnswersApiCalls;

    public function store(Request $request, CurrentOrganization $currentOrg): RedirectResponse|JsonResponse
    {
        $this->authorize('steer', Project::class);

        $settlement = InternalSettlement::create([...$this->validated($request, $currentOrg), 'organization_id' => $currentOrg->id()]);

        return $this->answer(__('app.saved'), $this->data($settlement), 201);
    }

    public function update(Request $request, InternalSettlement $internalSettlement, CurrentOrganization $currentOrg): RedirectResponse|JsonResponse
    {
        $this->authorize('steer', Project::class);

        $internalSettlement->update($this->validated($request, $currentOrg));

        return $this->answer(__('app.saved'), $this->data($internalSettlement));
    }

    public function destroy(InternalSettlement $internalSettlement): RedirectResponse|JsonResponse
    {
        $this->authorize('steer', Project::class);

        $internalSettlement->delete();

        return $this->answer(__('app.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, CurrentOrganization $currentOrg): array
    {
        $employee = Rule::exists('employees', 'id')->where('organization_id', $currentOrg->id());

        // Without a job given, it refers to none.
        return ['cost_center_id' => null, ...$request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'label' => ['required', 'string', 'max:120'],
            'from_employee_id' => ['required', 'uuid', $employee],
            'to_employee_id' => ['required', 'uuid', 'different:from_employee_id', $employee],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('organization_id', $currentOrg->id())],
            'amount' => ['required', 'numeric', 'between:0.01,99999999999.99'],
        ])];
    }

    /** @return array<string, mixed> */
    private function data(InternalSettlement $settlement): array
    {
        return $settlement->only('id', 'year', 'label', 'from_employee_id', 'to_employee_id', 'cost_center_id', 'amount');
    }
}
