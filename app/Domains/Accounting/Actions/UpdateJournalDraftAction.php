<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\Exceptions\AlreadyPostedException;
use App\Domains\Accounting\Exceptions\DuplicateReferenceException;
use App\Domains\Accounting\Exceptions\LockedReversalDraftException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * Updates the header and lines of an unposted journal entry draft.
 *
 * The single shared write path for editing a draft: the plain
 * `PUT /journal-entries/{id}` web/API route and a correction's replacement
 * draft (via {@see PrepareJournalCorrectionAction} / the future
 * `PUT .../replacement` endpoint) both go through this action, so balance,
 * account, and VAT validation can never drift between the two call sites.
 *
 * A correction's reversal draft is never a valid target: it is derived from
 * the original and locked for the lifetime of the correction.
 */
class UpdateJournalDraftAction
{
    public function __construct(
        private LedgerService $ledgerService,
    ) {}

    /**
     * @throws AlreadyPostedException When the entry is already posted
     * @throws LockedReversalDraftException When the entry is a correction's locked reversal draft
     * @throws DuplicateReferenceException When another entry already uses the given reference
     */
    public function execute(JournalEntry $draft, JournalEntryData $data): JournalEntry
    {
        if ($draft->is_posted) {
            throw new AlreadyPostedException('Cannot edit a posted journal entry.');
        }

        if ($this->isLockedReversalDraft($draft)) {
            throw new LockedReversalDraftException(
                "Journal entry {$draft->id} is a correction's reversal draft and cannot be edited directly."
            );
        }

        $vatRateIds = $this->ledgerService->validateDraftLines($draft->organization_id, $data->lines);
        $this->assertReferenceAvailable($draft, $data->reference);

        return DB::transaction(function () use ($draft, $data): JournalEntry {
            $draft->lines()->delete();
            $draft->update([
                'date' => $data->date,
                'reference' => $data->reference,
                'description' => $data->description,
            ]);

            foreach ($data->lines as $line) {
                TransactionLine::create([
                    'journal_entry_id' => $draft->id,
                    'account_id' => $line->accountId,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'description' => $line->description,
                    'vat_rate_id' => $line->vatRateId,
                    'vat_amount' => $line->vatAmount,
                    'vat_type' => $line->vatType,
                    'vat_figure' => $line->vatFigure,
                ]);
            }

            return $draft->fresh(['lines.account']);
        });
    }

    private function isLockedReversalDraft(JournalEntry $draft): bool
    {
        return JournalCorrection::withoutGlobalScopes()
            ->where('reversal_journal_entry_id', $draft->id)
            ->exists();
    }

    /** @throws DuplicateReferenceException */
    private function assertReferenceAvailable(JournalEntry $draft, ?string $reference): void
    {
        if ($reference === null) {
            return;
        }

        $exists = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $draft->organization_id)
            ->where('reference', $reference)
            ->whereKeyNot($draft->id)
            ->exists();

        if ($exists) {
            throw new DuplicateReferenceException(
                "A journal entry with reference '{$reference}' already exists in this organization."
            );
        }
    }
}
