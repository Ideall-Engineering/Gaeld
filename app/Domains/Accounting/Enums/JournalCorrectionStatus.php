<?php

namespace App\Domains\Accounting\Enums;

use App\Domains\Accounting\Models\JournalCorrection;

/**
 * Lifecycle status of a {@see JournalCorrection}.
 *
 * A correction starts as a `Draft` (reversal and replacement entries exist
 * but have no ledger effect) and becomes `Posted` only when both entries are
 * posted together. There is no further status: a posted correction cannot be
 * undone, and a draft correction is either posted or deleted (cancelled).
 */
enum JournalCorrectionStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}
