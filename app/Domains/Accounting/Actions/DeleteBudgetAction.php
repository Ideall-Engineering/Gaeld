<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\Budget;
use App\Domains\Reporting\Services\DashboardService;

/**
 * Removes a monthly budget target.
 *
 * Shared by the web route and the accountant-api module, mirroring
 * {@see UpsertBudgetAction}. A budget carries no ledger effect, so there is
 * nothing to reverse and no period lock to respect — the delete is
 * unconditional once the caller is authorized.
 *
 * Flushes the report and dashboard caches for the same reason as the upsert:
 * a removed target has to disappear from the report right away, not in half
 * an hour.
 */
class DeleteBudgetAction
{
    public function __construct(
        private DashboardService $dashboardService,
    ) {}

    public function execute(Budget $budget): void
    {
        $organizationId = $budget->organization_id;

        $budget->delete();

        $this->dashboardService->flushCache($organizationId);
    }
}
