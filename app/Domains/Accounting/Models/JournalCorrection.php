<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Actions\PostJournalCorrectionAction;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Enums\JournalCorrectionStatus;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Groups a reversal draft/entry and a replacement draft/entry into one
 * correction of an original, unchanged {@see JournalEntry}.
 *
 * The reversal and replacement only affect the ledger once this record's
 * status transitions from `draft` to `posted`, which happens atomically for
 * both entries via {@see PostJournalCorrectionAction}.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $original_journal_entry_id
 * @property string $reversal_journal_entry_id
 * @property string $replacement_journal_entry_id
 * @property JournalCorrectionStatus $status
 * @property string $reason
 * @property JournalCorrectionSource $source
 * @property int|null $user_id
 * @property int|null $token_id
 * @property string|null $client_operation_id
 * @property string|null $request_hash
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read JournalEntry $original
 * @property-read JournalEntry $reversal
 * @property-read JournalEntry $replacement
 */
class JournalCorrection extends Model
{
    /** @use HasFactory<Factory<static>> */
    use Auditable, BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'original_journal_entry_id',
        'reversal_journal_entry_id',
        'replacement_journal_entry_id',
        'status',
        'reason',
        'source',
        'user_id',
        'token_id',
        'client_operation_id',
        'request_hash',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => JournalCorrectionStatus::class,
            'source' => JournalCorrectionSource::class,
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'original_journal_entry_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'replacement_journal_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDraft(): bool
    {
        return $this->status === JournalCorrectionStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === JournalCorrectionStatus::Posted;
    }
}
