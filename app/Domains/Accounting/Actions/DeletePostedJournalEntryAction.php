<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\DTOs\JournalEntryChangeContext;
use App\Domains\Accounting\Enums\JournalEntryRevisionKind;
use App\Domains\Accounting\Events\JournalEntryDeleted;
use App\Domains\Accounting\Exceptions\JournalEntryNotEditableException;
use App\Domains\Accounting\Exceptions\StaleJournalEntryRevisionException;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
use App\Domains\Accounting\Services\JournalEntryRevisionRecorder;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deletes a posted journal entry while its period is still open.
 *
 * Only plain manual entries qualify ({@see JournalEntryEditStateService}):
 * anything linked to an invoice, expense, payroll run, bank transaction,
 * asset, lettrage or correction would be left pointing at nothing. The last
 * state survives in `journal_entry_revisions`, which also keeps the
 * reference reserved.
 */
class DeletePostedJournalEntryAction
{
    public function __construct(
        private LedgerService $ledger,
        private JournalEntryEditStateService $editState,
        private JournalEntryRevisionRecorder $revisions,
    ) {}

    /**
     * @throws StaleJournalEntryRevisionException When the entry changed since the caller loaded it
     * @throws JournalEntryNotEditableException When the entry is locked
     */
    public function execute(JournalEntry $entry, JournalEntryChangeContext $context): void
    {
        $deleted = DB::transaction(function () use ($entry, $context): array {
            $locked = JournalEntry::withoutGlobalScopes()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->revision !== $context->expectedRevision) {
                throw new StaleJournalEntryRevisionException($context->expectedRevision, $locked->revision);
            }

            $this->editState->assertDeletable($locked);

            $revision = $this->revisions->record($locked, JournalEntryRevisionKind::Deleted, $context);

            VatEntry::query()->where('journal_entry_id', $locked->id)->get()->each->delete();
            TransactionLine::query()->where('journal_entry_id', $locked->id)->get()->each->delete();
            $locked->delete();

            return [$locked, $revision->id];
        });

        [$entryRow, $revisionId] = $deleted;

        $this->ledger->flushCache($entryRow->organization_id);

        JournalEntryDeleted::dispatch(
            Str::uuid()->toString(),
            $entryRow->organization_id,
            $entryRow->id,
            $entryRow->reference,
            $entryRow->revision,
            $revisionId,
        );
    }
}
