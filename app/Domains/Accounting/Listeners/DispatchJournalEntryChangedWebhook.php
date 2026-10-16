<?php

namespace App\Domains\Accounting\Listeners;

use App\Domains\Accounting\Events\JournalEntryAmended;
use App\Domains\Accounting\Events\JournalEntryDeleted;
use App\Domains\Api\Services\WebhookService;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Delivers `journal_entry.updated` and `journal_entry.deleted` webhooks for
 * posted entries changed in place. Both events fire after the commit, so a
 * subscriber never learns of a change that was rolled back.
 */
class DispatchJournalEntryChangedWebhook
{
    public function __construct(
        private WebhookService $webhooks,
    ) {}

    public function handleAmended(JournalEntryAmended $event): void
    {
        $this->webhooks->dispatch($event->organizationId, 'journal_entry.updated', [
            'event_id' => $event->eventId,
            'journal_entry_id' => $event->journalEntryId,
            'previous_revision' => $event->previousRevision,
            'revision' => $event->revision,
            'revision_id' => $event->revisionId,
        ]);
    }

    public function handleDeleted(JournalEntryDeleted $event): void
    {
        $this->webhooks->dispatch($event->organizationId, 'journal_entry.deleted', [
            'event_id' => $event->eventId,
            'journal_entry_id' => $event->journalEntryId,
            'reference' => $event->reference,
            'revision' => $event->revision,
            'revision_id' => $event->revisionId,
        ]);
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JournalEntryAmended::class, [self::class, 'handleAmended']);
        $events->listen(JournalEntryDeleted::class, [self::class, 'handleDeleted']);
    }
}
