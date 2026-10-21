<?php

namespace App\Domains\Invoicing\Enums;

use Carbon\Carbon;

/** Frequency at which a recurring invoice is generated. */
enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /**
     * The due day after the given one. Months never overflow: a schedule on
     * the 31st falls on the last day of shorter months instead of skipping
     * them, and returns to its own day (the anchor) once the month allows.
     */
    public function nextDate(Carbon $from, ?int $anchorDay = null): Carbon
    {
        $months = match ($this) {
            self::Weekly => null,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };

        if ($months === null) {
            return $from->copy()->addWeek();
        }

        $next = $from->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $next->day(min($anchorDay ?? $from->day, $next->daysInMonth));
    }

    public function label(): string
    {
        return match ($this) {
            self::Weekly => __('app.recurrence_frequency_weekly'),
            self::Monthly => __('app.recurrence_frequency_monthly'),
            self::Quarterly => __('app.recurrence_frequency_quarterly'),
            self::Yearly => __('app.recurrence_frequency_yearly'),
        };
    }
}
