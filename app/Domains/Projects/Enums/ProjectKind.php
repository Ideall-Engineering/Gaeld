<?php

namespace App\Domains\Projects\Enums;

/**
 * What a project stands for: an area that groups projects, a customer
 * project, or an internal cost block.
 */
enum ProjectKind: string
{
    case Area = 'area';
    case Project = 'project';
    case Internal = 'internal';

    public function label(): string
    {
        return __('app.project_kind_'.$this->value);
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
