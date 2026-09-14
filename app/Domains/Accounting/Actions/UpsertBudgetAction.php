<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Reporting\Services\DashboardService;

/**
 * Sets the monthly budget target for one account in one fiscal year.
 *
 * The single shared write path for budgets: the web route
 * (`POST`/`PATCH /accounting/budgets`) and the accountant-api module's
 * `PUT /api/v1/budgets/{account_code}/{fiscal_year}` both go through this
 * action, so the upsert semantics can never drift between the two call sites.
 *
 * Upsert rather than create/update, because `(organization_id, account_id,
 * fiscal_year)` is unique: setting a target that already exists is a normal
 * correction, not a conflict. That also makes the API route naturally
 * idempotent — repeating the same call yields the same single row.
 *
 * Flushes the report and dashboard caches afterwards. ReportingService caches
 * the profit and loss statement — budget column included — for 30 minutes,
 * and LedgerService only flushes on a ledger write, which a budget is not.
 * Without this the new target stays invisible in the report for up to half an
 * hour, in the web UI just as much as over the API. The ledger tag is left
 * alone on purpose: a budget moves no balance.
 */
class UpsertBudgetAction
{
    public function __construct(
        private DashboardService $dashboardService,
    ) {}

    public function execute(string $organizationId, Account $account, int $fiscalYear, string $monthlyAmount): Budget
    {
        $budget = Budget::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'account_id' => $account->id,
                'fiscal_year' => $fiscalYear,
            ],
            [
                'monthly_amount' => $monthlyAmount,
            ],
        );

        $this->dashboardService->flushCache($organizationId);

        return $budget;
    }
}
