<?php

namespace App\Domains\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after the commit that amended a posted journal entry in
 * place. `revisionId` points at the snapshot of the state before the change.
 */
class JournalEntryAmended
{
    use Dispatchable;

    public function __construct(
        public readonly string $eventId,
        public readonly string $organizationId,
        public readonly string $journalEntryId,
        public readonly int $previousRevision,
        public readonly int $revision,
        public readonly string $revisionId,
    ) {}
}
