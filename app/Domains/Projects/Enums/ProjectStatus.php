<?php

namespace App\Domains\Projects\Enums;

/**
 * Where a job stands: open (in prospect, no quote out yet), a quote is out
 * (possible), it is being done, its invoice is out, done (every invoice
 * paid) or lost. A job's quotes set it as they are sent, accepted or
 * declined, its invoices as they are sent and paid.
 */
enum ProjectStatus: string
{
    case Pipeline = 'pipeline';
    case Possible = 'possible';
    case InProgress = 'in_progress';
    case Invoiced = 'invoiced';
    case Done = 'done';
    case Lost = 'lost';

    public function label(): string
    {
        return __('app.project_status_'.$this->value);
    }

    /** The work is over: invoiced or done. */
    public function isBilled(): bool
    {
        return $this === self::Invoiced || $this === self::Done;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
