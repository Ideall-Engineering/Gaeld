<?php

namespace App\Domains\Banking\Listeners;

use App\Domains\Automation\Jobs\RunAutomationJob;
use App\Domains\Banking\Events\BankStatementImported;
use App\Domains\Banking\Jobs\GenerateBankRuleSuggestionsJob;
use App\Support\FeatureFlag;

/**
 * Turns a finished import into queued suggestion work.
 *
 * With the automation module on, the work goes through the automation runner so
 * it lands in the run log and obeys the organization's switch. Without it, the
 * plain job still runs — the rule engine is useful on its own, and making it
 * depend on the automation module would be a step backwards.
 *
 * The import id is the event key either way, so a re-sent statement is handled
 * once.
 */
class QueueBankRuleSuggestions
{
    public function handle(BankStatementImported $event): void
    {
        if (FeatureFlag::disabled('rule_engine') || $event->transactionCount === 0) {
            return;
        }

        if (FeatureFlag::enabled('automation')) {
            RunAutomationJob::dispatch(
                'bank_import_suggestions',
                $event->organizationId,
                'import:'.$event->bankImportId,
                ['bank_import_id' => $event->bankImportId],
            );

            return;
        }

        GenerateBankRuleSuggestionsJob::dispatch($event->bankImportId);
    }
}
