<?php

namespace App\Domains\Automation\Models;

use App\Domains\Automation\Enums\AutomationStatus;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One occasion on which an automation ran, or was prevented from running.
 *
 * Not auditable via the Auditable trait: the run log *is* the audit trail, and
 * auditing it again would only duplicate rows.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $automation
 * @property string $event_key
 * @property AutomationStatus $status
 * @property int $attempts
 * @property array<string, mixed>|null $summary
 * @property string|null $message
 * @property int|null $triggered_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class AutomationRun extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'automation',
        'event_key',
        'status',
        'attempts',
        'summary',
        'message',
        'triggered_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationStatus::class,
            'summary' => 'array',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Findings the run wants a person to look at.
     *
     * @return array<int, string>
     */
    public function findings(): array
    {
        $findings = $this->summary['findings'] ?? [];

        return is_array($findings) ? $findings : [];
    }
}
