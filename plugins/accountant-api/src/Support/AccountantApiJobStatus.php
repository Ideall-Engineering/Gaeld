<?php

namespace Plugins\AccountantApi\Support;

use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks an async module operation. See the migration for why this is
 * currently unused by any endpoint.
 */
class AccountantApiJobStatus extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'accountant_api_job_statuses';

    protected $fillable = [
        'organization_id',
        'job_type',
        'status',
        'payload',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
