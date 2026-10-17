<?php

namespace App\Domains\Projects\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Users\Models\User;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An entry whose lines were rebuilt and whose allocations could not all be
 * matched to the new lines. `previous` holds what was left over, so a person
 * can decide where it belongs.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $journal_entry_id
 * @property string $reason
 * @property array<int, array<string, mixed>> $previous
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property Carbon $created_at
 * @property-read JournalEntry $journalEntry
 */
class AllocationReview extends Model
{
    use BelongsToOrganization;

    public const REASON_LINES_REBUILT = 'lines_rebuilt';

    protected $fillable = [
        'organization_id',
        'journal_entry_id',
        'reason',
        'previous',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'previous' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
