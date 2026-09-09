<?php

namespace App\Console\Commands;

use App\Domains\Automation\Jobs\RunAutomationJob;
use App\Domains\Automation\Services\AutomationRegistry;
use App\Domains\Organizations\Models\Organization;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;

/**
 * Queues the scheduled automations for every organization.
 *
 * The event key is the calendar day, so a scheduler that fires twice, a worker
 * that retries, and an operator who runs this by hand on the same day all
 * describe the same occasion — and only the first one does the work.
 */
class RunAutomationsCommand extends Command
{
    protected $signature = 'gaeld:run-automations
                            {--automation=* : Limit to these automation keys}
                            {--organization= : Limit to one organization}
                            {--date= : Treat this date as the occasion (Y-m-d, default today)}';

    protected $description = 'Queue the daily automations for every organization';

    /**
     * Automations that make sense on a daily schedule.
     *
     * bank_import_suggestions is absent on purpose: it belongs to an import, not
     * to a date, and is dispatched by the import itself.
     */
    private const SCHEDULED = [
        'qr_payment_matching',
        'recurring_invoice_drafts',
        'unclear_payments_review',
        'overdue_invoice_report',
        'payment_reminders',
        'missing_documents_report',
        'tax_declaration_readiness',
    ];

    public function handle(AutomationRegistry $registry): int
    {
        if (FeatureFlag::disabled('automation')) {
            $this->warn('The automation module is disabled (FEATURE_AUTOMATION).');

            return self::SUCCESS;
        }

        $date = (string) ($this->option('date') ?: now()->toDateString());

        /** @var array<int, string> $requested */
        $requested = (array) $this->option('automation');
        $keys = $requested !== [] ? $requested : self::SCHEDULED;

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'name']);

        $queued = 0;
        $skipped = 0;

        foreach ($organizations as $organization) {
            foreach ($keys as $key) {
                if (! $registry->has($key)) {
                    $this->warn("Unknown automation: {$key}");

                    continue;
                }

                if (! $registry->isEnabled($key, $organization->id)) {
                    $skipped++;

                    continue;
                }

                RunAutomationJob::dispatch($key, $organization->id, 'daily:'.$date);
                $queued++;
            }
        }

        $this->info(sprintf(
            '%d automation runs queued for %s, %d skipped as disabled.',
            $queued,
            $date,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
