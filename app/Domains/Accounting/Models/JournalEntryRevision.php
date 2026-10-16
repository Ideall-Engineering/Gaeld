<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Enums\JournalEntryRevisionKind;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The complete state a posted journal entry had before it was amended or
 * deleted.
 *
 * `revision` is the entry's revision number at the time of the snapshot, so
 * the current entry plus all of its revisions form an unbroken history.
 * Revisions are append-only and outlive the entry they describe: there is
 * no foreign key to `journal_entries` and no update or delete path.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $journal_entry_id
 * @property int $revision
 * @property JournalEntryRevisionKind $kind
 * @property string|null $reference
 * @property array<string, mixed> $snapshot
 * @property string|null $reason
 * @property int|null $user_id
 * @property int|null $token_id
 * @property JournalCorrectionSource $source
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class JournalEntryRevision extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'journal_entry_id',
        'revision',
        'kind',
        'reference',
        'snapshot',
        'reason',
        'user_id',
        'token_id',
        'source',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'kind' => JournalEntryRevisionKind::class,
            'snapshot' => 'array',
            'source' => JournalCorrectionSource::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Journal entry revisions are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Journal entry revisions are append-only.');
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
