<?php

namespace App\Console\Commands;

use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\InvoiceJobStatusService;
use Illuminate\Console\Command;

/**
 * Once, for jobs from before the status "invoice sent": set every job
 * that has invoices in Gäld from them as InvoiceJobStatusService would.
 * A job without any was billed elsewhere (imported from the spreadsheet)
 * and keeps its status, lost jobs stay lost. Shows the changes and writes
 * them only with --apply.
 */
class TidyJobStatusCommand extends Command
{
    protected $signature = 'gaeld:auftraege-nach-rechnungen {--apply : Write the changes}';

    protected $description = 'Set the status of existing jobs from their invoices (invoice sent, done, in progress)';

    public function handle(InvoiceJobStatusService $jobStatus): int
    {
        $rows = [];
        $jobs = Project::withoutGlobalScopes()
            ->where('kind', ProjectKind::Project->value)
            ->where('status', '<>', ProjectStatus::Lost->value)
            ->orderBy('organization_id')->orderBy('code')
            ->get();

        foreach ($jobs as $job) {
            $status = $jobStatus->fromInvoices($job);
            if ($status === null || $status === ProjectStatus::InProgress || $status === $job->status) {
                continue;
            }
            $rows[] = [$job->code, $job->name, $job->status->label(), $status->label()];
            if ($this->option('apply')) {
                $job->update(['status' => $status]);
            }
        }

        if ($rows === []) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(['Code', 'Name', 'Before', 'After'], $rows);
        $this->info(count($rows).($this->option('apply') ? ' jobs changed.' : ' jobs would change. Run again with --apply to write them.'));

        return self::SUCCESS;
    }
}
