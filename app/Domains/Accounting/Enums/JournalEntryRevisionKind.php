<?php

namespace App\Domains\Accounting\Enums;

enum JournalEntryRevisionKind: string
{
    case Updated = 'updated';
    case Deleted = 'deleted';
}
