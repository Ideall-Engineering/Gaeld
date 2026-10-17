<?php

namespace App\Domains\Projects\Enums;

/**
 * Where a project stands, from a first lead to done or lost.
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
