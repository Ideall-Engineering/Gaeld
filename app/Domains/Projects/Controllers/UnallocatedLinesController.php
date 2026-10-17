<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Services\AllocationSuggestionService;
use App\Domains\Projects\Services\LineAllocationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Revenue and expense lines without a project, with suggestions, to catch
 * up on the year (spec 009, User Story 9).
 */
class UnallocatedLinesController extends Controller
{
    public function index(Request $request, AllocationSuggestionService $suggestions, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('allocate', Project::class);

        $year = $request->integer('year', (int) now()->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;

        return Inertia::render('Projects/Unallocated', [
            'lines' => $suggestions->unallocated($currentOrg->id(), "{$year}-01-01", "{$year}-12-31"),
            'limit' => AllocationSuggestionService::LIMIT,
            'year' => $year,
            'projects' => ProjectQuery::options(),
        ]);
    }

    /**
     * Allocate the chosen lines, each wholly to its chosen project.
     */
    public function apply(Request $request, LineAllocationService $allocations, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('allocate', Project::class);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.line_id' => ['required', 'integer'],
            'items.*.project_id' => [
                'required',
                'integer',
                Rule::exists('cost_centers', 'id')->where('organization_id', $currentOrg->id())->where('is_active', true),
            ],
        ]);

        $lines = TransactionLine::query()
            ->whereIn('id', array_column($validated['items'], 'line_id'))
            ->whereHas('journalEntry', fn ($q) => $q->where('organization_id', $currentOrg->id()))
            ->get(['id', 'journal_entry_id'])
            ->keyBy('id');

        $byEntry = [];
        foreach ($validated['items'] as $item) {
            $line = $lines->get((int) $item['line_id']);
            if ($line === null) {
                continue;
            }
            $byEntry[$line->journal_entry_id][(int) $line->id] = $allocations->withProject((int) $line->id, (int) $item['project_id'], AllocationSource::Bulk);
        }

        DB::transaction(function () use ($byEntry, $allocations, $request): void {
            foreach ($byEntry as $entryId => $byLine) {
                $entry = JournalEntry::query()->findOrFail($entryId);
                $allocations->replaceForEntry($entry, $byLine, AllocationSource::Bulk, $request->user()?->id);
            }
        });

        return back()->with('success', __('app.allocations_bulk_done', ['count' => array_sum(array_map('count', $byEntry))]));
    }
}
