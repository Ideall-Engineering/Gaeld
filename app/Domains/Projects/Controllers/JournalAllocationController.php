<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Exceptions\InvalidAllocationException;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\LineAllocationService;
use App\Domains\Projects\Support\AllocationRules;
use App\Http\Controllers\Concerns\AnswersApiCalls;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Change which projects and people the lines of journal entries belong to,
 * whatever state the entries are in (spec 009, User Story 3).
 */
class JournalAllocationController extends Controller
{
    use AnswersApiCalls;

    public function update(
        Request $request,
        JournalEntry $journalEntry,
        LineAllocationService $allocations,
        CurrentOrganization $currentOrg,
    ): RedirectResponse {
        $this->authorize('view', $journalEntry);
        $this->authorize('allocate', Project::class);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['required', 'integer'],
            ...AllocationRules::for(
                $currentOrg->id(),
                keptProjectIds: AllocationRules::projectsOf($journalEntry),
                keptEmployeeIds: AllocationRules::employeesOf($journalEntry),
            ),
        ]);

        $byLine = [];
        foreach ($validated['lines'] as $line) {
            $byLine[(int) $line['id']] = array_values(array_map(
                fn (array $allocation) => LineAllocationData::fromArray($allocation),
                (array) ($line['allocations'] ?? []),
            ));
        }

        try {
            $allocations->replaceForEntry($journalEntry, $byLine, AllocationSource::Manual, $request->user()?->id);
        } catch (InvalidAllocationException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        return back()->with('success', __('app.allocations_saved'));
    }

    /**
     * Allocate the revenue and expense lines of many entries to one project.
     * Lines that already carry an allocation are left alone unless the
     * caller confirmed overwriting them.
     */
    public function bulk(
        Request $request,
        LineAllocationService $allocations,
        CurrentOrganization $currentOrg,
    ): RedirectResponse|JsonResponse {
        $this->authorize('allocate', Project::class);

        $orgId = $currentOrg->id();
        $validated = $request->validate([
            'entry_ids' => ['required', 'array', 'min:1', 'max:200'],
            'entry_ids.*' => ['required', 'uuid'],
            'project_id' => [
                'required',
                'integer',
                Rule::exists('cost_centers', 'id')->where('organization_id', $orgId)->where('is_active', true),
            ],
            'overwrite' => ['sometimes', 'boolean'],
            // Only these lines of the entries, e.g. the bookings of one cost block.
            'line_ids' => ['sometimes', 'array', 'max:500'],
            'line_ids.*' => ['integer'],
        ]);

        $overwrite = (bool) ($validated['overwrite'] ?? false);
        $entries = JournalEntry::query()->whereIn('id', $validated['entry_ids'])->get();

        $assigned = 0;
        $skipped = 0;

        // All or nothing: every entry is checked before any is changed.
        foreach ($entries as $entry) {
            $this->authorize('view', $entry);
        }

        DB::transaction(function () use ($entries, $overwrite, $validated, $allocations, $request, &$assigned, &$skipped): void {
            foreach ($entries as $entry) {

                $lines = TransactionLine::query()
                    ->where('journal_entry_id', $entry->id)
                    ->whereHas('account', fn ($q) => $q->whereIn('type', [AccountType::Revenue->value, AccountType::Expense->value]))
                    ->when(isset($validated['line_ids']), fn ($q) => $q->whereIn('id', $validated['line_ids']))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id);

                $allocated = LineAllocation::query()
                    ->whereIn('transaction_line_id', $lines)
                    ->distinct()
                    ->pluck('transaction_line_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $byLine = [];
                foreach ($lines as $lineId) {
                    if (! $overwrite && in_array($lineId, $allocated, true)) {
                        $skipped++;

                        continue;
                    }
                    $byLine[$lineId] = [new LineAllocationData(projectId: (int) $validated['project_id'], employeeId: null)];
                }

                if ($byLine !== []) {
                    $allocations->replaceForEntry($entry, $byLine, AllocationSource::Bulk, $request->user()?->id);
                    $assigned += count($byLine);
                }
            }
        });

        if ($this->wantsApiAnswer()) {
            return response()->json(['data' => ['assigned' => $assigned, 'skipped' => $skipped]]);
        }

        $response = back()->with('success', __('app.allocations_bulk_done', ['count' => $assigned]));

        return $skipped > 0
            ? $response->with('warning', __('app.allocations_bulk_skipped', ['count' => $skipped]))
            : $response;
    }
}
