<?php

namespace App\Domains\Accounting\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Thrown when an amendment or deletion was prepared against an older
 * revision of a journal entry than the one now stored.
 */
class StaleJournalEntryRevisionException extends DomainException
{
    public function __construct(
        public readonly int $expectedRevision,
        public readonly int $currentRevision,
    ) {
        parent::__construct(__('app.journal_entry_stale_revision'));
    }

    public function errorCode(): string
    {
        return 'stale_revision';
    }
}
