<?php

namespace App\Domains\Accounting\Enums;

use App\Domains\Accounting\Models\JournalCorrection;

/**
 * Where a {@see JournalCorrection} was initiated from.
 */
enum JournalCorrectionSource: string
{
    case Web = 'web';
    case Api = 'api';
}
