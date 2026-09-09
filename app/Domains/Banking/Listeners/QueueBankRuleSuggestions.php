<?php

namespace App\Domains\Banking\Listeners;

use App\Domains\Banking\Events\BankStatementImported;
use App\Domains\Banking\Jobs\GenerateBankRuleSuggestionsJob;
use App\Support\FeatureFlag;

/**
 * Turns a finished import into queued suggestion work.
 *
 * Kept as a listener rather than a call inside the import service so that
 * suggestion generation can be switched off, replaced, or joined by further
 * post-import steps without touching the importer.
 */
class QueueBankRuleSuggestions
{
    public function handle(BankStatementImported $event): void
    {
        if (FeatureFlag::disabled('rule_engine') || $event->transactionCount === 0) {
            return;
        }

        GenerateBankRuleSuggestionsJob::dispatch($event->bankImportId);
    }
}
