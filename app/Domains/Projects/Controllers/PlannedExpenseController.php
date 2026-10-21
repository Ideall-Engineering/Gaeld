<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\PlannedExpense;
use App\Http\Controllers\Concerns\AnswersApiCalls;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Single expected expenses entered by hand on the steering page (spec 012,
 * stage 3), by everyone who sees it. Over the API as well.
 */
class PlannedExpenseController extends Controller
{
    use AnswersApiCalls;

    public function store(Request $request, CurrentOrganization $currentOrg): RedirectResponse|JsonResponse
    {
        $this->authorize('create', PlannedExpense::class);

        $planned = PlannedExpense::create([...$this->validated($request, $currentOrg), 'organization_id' => $currentOrg->id()]);

        return $this->answer(__('app.saved'), $this->data($planned), 201);
    }

    /**
     * Change it, or mark it done (`done`: true) once it is paid or booked.
     */
    public function update(Request $request, PlannedExpense $plannedExpense, CurrentOrganization $currentOrg): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $plannedExpense);

        $validated = $this->validated($request, $currentOrg, partial: true);
        if (array_key_exists('done', $validated)) {
            $validated['done_at'] = $validated['done'] ? now() : null;
            unset($validated['done']);
        }
        $plannedExpense->update($validated);

        return $this->answer(__('app.saved'), $this->data($plannedExpense));
    }

    public function destroy(PlannedExpense $plannedExpense): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $plannedExpense);

        $plannedExpense->delete();

        return $this->answer(__('app.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, CurrentOrganization $currentOrg, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'date' => [$required, 'date'],
            'label' => [$required, 'string', 'max:120'],
            'amount' => [$required, 'numeric', 'between:-99999999999.99,99999999999.99'],
            'vat_amount' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999999.99'],
            'account_code' => ['sometimes', 'nullable', 'string', 'max:20', Rule::exists('accounts', 'code')->where('organization_id', $currentOrg->id())],
            'done' => ['sometimes', 'boolean'],
        ]);

        // An empty VAT field means no VAT.
        if (! $partial || array_key_exists('vat_amount', $validated)) {
            $validated['vat_amount'] = $validated['vat_amount'] ?? '0';
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function data(PlannedExpense $planned): array
    {
        return [
            'id' => $planned->id,
            'date' => $planned->date->toDateString(),
            'label' => $planned->label,
            'amount' => (string) $planned->amount,
            'vat_amount' => (string) $planned->vat_amount,
            'account_code' => $planned->account_code,
            'done_at' => $planned->done_at?->toIso8601String(),
        ];
    }
}
