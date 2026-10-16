<?php

namespace App\Domains\Accounting\Enums;

/**
 * Whether an organization may amend or delete posted journal entries while
 * their period is still open.
 *
 * `Strict` keeps the original behaviour: a posted entry is final and only
 * the guided correction or a reversal can change its effect.
 */
enum PostedEntryEditMode: string
{
    case Strict = 'strict';
    case Flexible = 'flexible';
}
