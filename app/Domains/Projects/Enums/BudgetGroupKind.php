<?php

namespace App\Domains\Projects\Enums;

/**
 * Running costs are projected over the whole year; a one-off expense is
 * what it is.
 */
enum BudgetGroupKind: string
{
    case Running = 'running';
    case OneOff = 'one_off';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
