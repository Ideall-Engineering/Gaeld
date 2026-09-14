<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;

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
 */
class UpsertBudgetAction
{
    public function execute(string $organizationId, Account $account, int $fiscalYear, string $monthlyAmount): Budget
    {
        return Budget::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'account_id' => $account->id,
                'fiscal_year' => $fiscalYear,
            ],
            [
                'monthly_amount' => $monthlyAmount,
            ],
        );
    }
}
