<?php

namespace App\Domains\Accounting\Listeners;

use App\Domains\Accounting\Events\JournalEntryCorrected;
use App\Domains\Api\Services\WebhookService;

/**
 * Delivers `journal_entry.corrected` webhooks (plan.md T051). The event is
 * dispatched only after the database commit that posts both entries, so a
 * subscriber never sees the webhook before the correction is durably
 * effective.
 */
class DispatchJournalEntryCorrectedWebhook
{
    public function __construct(
        private WebhookService $webhooks,
    ) {}

    public function handle(JournalEntryCorrected $event): void
    {
        $this->webhooks->dispatch($event->organizationId, 'journal_entry.corrected', [
            'event_id' => $event->eventId,
            'original_journal_entry_id' => $event->originalJournalEntryId,
            'reversal_journal_entry_id' => $event->reversalJournalEntryId,
            'replacement_journal_entry_id' => $event->replacementJournalEntryId,
        ]);
    }
}
