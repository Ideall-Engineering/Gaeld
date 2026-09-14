<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\Budget;

/**
 * Removes a monthly budget target.
 *
 * Shared by the web route and the accountant-api module, mirroring
 * {@see UpsertBudgetAction}. A budget carries no ledger effect, so there is
 * nothing to reverse and no period lock to respect — the delete is
 * unconditional once the caller is authorized.
 */
class DeleteBudgetAction
{
    public function execute(Budget $budget): void
    {
        $budget->delete();
    }
}
