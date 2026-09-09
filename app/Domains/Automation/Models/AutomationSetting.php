<?php

namespace App\Domains\Automation\Models;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whether one automation may run for one organization.
 *
 * Absence of a row means "never decided", which resolves to the handler's own
 * default — off for anything that writes. Turning something on is therefore
 * always a recorded act, with the person who did it attached.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $automation
 * @property bool $is_enabled
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
class AutomationSetting extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'automation',
        'is_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
