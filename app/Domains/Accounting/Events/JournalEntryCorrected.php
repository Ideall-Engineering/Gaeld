<?php

namespace App\Domains\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched only after the database commit that posts a correction's
 * reversal and replacement entries together. Carries UUIDs only (not
 * models) so the module's webhook adapter can serialize it directly.
 */
class JournalEntryCorrected
{
    use Dispatchable;

    public function __construct(
        public readonly string $eventId,
        public readonly string $organizationId,
        public readonly string $originalJournalEntryId,
        public readonly string $reversalJournalEntryId,
        public readonly string $replacementJournalEntryId,
    ) {}
}
