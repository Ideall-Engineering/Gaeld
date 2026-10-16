<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Accounting\Enums\JournalEntryRevisionKind;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Api\Resources\JournalEntryRevisionResource;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Read access to the prior states of amended and deleted posted entries.
 */
class JournalEntryRevisionApiController extends Controller
{
    /**
     * List revisions of a journal entry
     *
     * Every earlier state of the entry, oldest first. The current state is
     * the entry itself.
     *
     * @urlParam journalEntry string required The journal entry UUID. Example: 9c8f1b2a-3d4e-5f67-8901-abcdef123456
     */
    public function forEntry(
        JournalEntry $journalEntry,
        CurrentOrganization $currentOrganization,
    ): AnonymousResourceCollection {
        abort_unless($journalEntry->organization_id === $currentOrganization->id(), 404);
        $this->authorize('view', $journalEntry);

        return JournalEntryRevisionResource::collection($journalEntry->revisions()->get());
    }

    /**
     * List recent revisions
     *
     * Revisions across all entries, newest first; the only way to reach the
     * last state of a deleted entry.
     *
     * @queryParam kind string Only `updated` or `deleted` revisions. Example: deleted
     * @queryParam journal_entry_id string Only revisions of this entry, also after it was deleted.
     * @queryParam since string Only revisions recorded on or after this date. Example: 2026-10-01
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JournalEntryRevision::class);

        $validated = $request->validate([
            'kind' => ['nullable', Rule::enum(JournalEntryRevisionKind::class)],
            'journal_entry_id' => ['nullable', 'uuid'],
            'since' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $revisions = JournalEntryRevision::query()
            ->when($validated['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
            ->when($validated['journal_entry_id'] ?? null, fn ($query, string $id) => $query->where('journal_entry_id', $id))
            ->when($validated['since'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        return JournalEntryRevisionResource::collection($revisions);
    }
}
