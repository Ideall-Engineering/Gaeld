<?php

namespace App\Domains\Projects\Enums;

/**
 * Where a job stands: open (in prospect, no quote out yet), a quote is out
 * (possible), it is being done, done or lost. A job's quotes set it as they
 * are sent, accepted or declined.
 */
enum ProjectStatus: string
{
    case Pipeline = 'pipeline';
    case Possible = 'possible';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Lost = 'lost';

    public function label(): string
    {
        return __('app.project_status_'.$this->value);
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
