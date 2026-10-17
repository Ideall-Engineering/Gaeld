<?php

namespace App\Domains\Projects\Models;

use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Share of a project's value that a person contributes (spec 009, stage 5).
 * Set on an area, it holds for every project below without its own.
 *
 * @property int $id
 * @property string $organization_id
 * @property int $cost_center_id
 * @property string $employee_id
 * @property string $share
 */
class ProjectShare extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = ['organization_id', 'cost_center_id', 'employee_id', 'share'];

    protected function casts(): array
    {
        return ['share' => 'decimal:4'];
    }
}
