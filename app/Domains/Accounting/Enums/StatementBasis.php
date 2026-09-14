<?php

namespace App\Domains\Accounting\Enums;

use App\Domains\Accounting\Services\LedgerQueryService;

/**
 * Which set of postings a figure is built from.
 *
 * A report and the account statement it drills into must agree on this, or the
 * statement's total contradicts the figure that was clicked. Every report that
 * links to a statement therefore names its basis, and the statement computes on
 * the one it was given.
 */
enum StatementBasis: string
{
    /**
     * Business activity only: year-end closings and historical summaries left
     * out. The basis of the dashboard's month view.
     */
    case Operational = 'operational';

    /**
     * Everything posted, structural entries included. The basis of the profit
     * and loss statement and the balance sheet, whose figures come from
     * {@see LedgerQueryService::accountBalance()}.
     */
    case Ledger = 'ledger';

    public function excludesStructuralEntries(): bool
    {
        return $this === self::Operational;
    }
}
