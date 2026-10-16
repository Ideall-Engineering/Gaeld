<?php

namespace App\Domains\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after the commit that deleted a posted journal entry.
 * `revisionId` points at the snapshot of its last state.
 */
class JournalEntryDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $eventId,
        public readonly string $organizationId,
        public readonly string $journalEntryId,
        public readonly ?string $reference,
        public readonly int $revision,
        public readonly string $revisionId,
    ) {}
}
