<?php

namespace App\Support\Observers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Exceptions\ArchivedRecordException;

/**
 * Extends the archive lock of a journal entry to its lines.
 *
 * {@see LocksArchivedRecord} only guards the entry row itself, so without
 * this observer the amounts of an archived entry could still be changed,
 * added to or removed line by line.
 */
class LocksArchivedTransactionLine
{
    public function creating(TransactionLine $line): void
    {
        $this->assertParentNotArchived($line->journal_entry_id);
    }

    public function updating(TransactionLine $line): void
    {
        $this->assertParentNotArchived($line->getOriginal('journal_entry_id'));
        $this->assertParentNotArchived($line->journal_entry_id);
    }

    public function deleting(TransactionLine $line): void
    {
        $this->assertParentNotArchived($line->getOriginal('journal_entry_id'));
    }

    private function assertParentNotArchived(mixed $journalEntryId): void
    {
        if ($journalEntryId === null) {
            return;
        }

        $archived = JournalEntry::withoutGlobalScopes()
            ->whereKey($journalEntryId)
            ->whereNotNull('archived_at')
            ->exists();

        if ($archived) {
            throw ArchivedRecordException::locked('journal_entry', (string) $journalEntryId);
        }
    }
}
