<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DTOs\JournalEntryChangeContext;
use App\Domains\Accounting\Enums\JournalEntryRevisionKind;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;

/**
 * Freezes the complete current state of a posted journal entry before it
 * is amended or deleted.
 *
 * The snapshot holds the header, every line and every VAT entry exactly as
 * stored, so any earlier state can be shown or restored without consulting
 * the activity log (which records rows separately, misses bulk deletes and
 * is pruned after a year).
 */
class JournalEntryRevisionRecorder
{
    public function record(
        JournalEntry $entry,
        JournalEntryRevisionKind $kind,
        JournalEntryChangeContext $context,
    ): JournalEntryRevision {
        $lines = TransactionLine::query()
            ->where('journal_entry_id', $entry->id)
            ->orderBy('id')
            ->get();
        $vatEntries = VatEntry::query()
            ->where('journal_entry_id', $entry->id)
            ->orderBy('id')
            ->get();

        return JournalEntryRevision::create([
            'organization_id' => $entry->organization_id,
            'journal_entry_id' => $entry->id,
            'revision' => $entry->revision,
            'kind' => $kind,
            'reference' => $entry->reference,
            'snapshot' => [
                'entry' => $entry->withoutRelations()->attributesToArray(),
                'lines' => $lines->map(fn (TransactionLine $line) => $line->attributesToArray())->all(),
                'vat_entries' => $vatEntries->map(fn (VatEntry $vat) => $vat->attributesToArray())->all(),
            ],
            'reason' => $context->reason !== null && trim($context->reason) !== '' ? trim($context->reason) : null,
            'user_id' => $context->userId,
            'token_id' => $context->tokenId,
            'source' => $context->source,
            'idempotency_key' => $context->idempotencyKey,
        ]);
    }
}
