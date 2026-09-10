<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Accounting\Enums\VatEntryType;
use App\Support\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single debit or credit line within a {@see JournalEntry}.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property string $debit
 * @property string $credit
 * @property string|null $description
 * @property string|null $vat_rate_id
 * @property string|null $vat_amount
 * @property VatEntryType|null $vat_type
 * @property string|null $vat_figure
 */
class TransactionLine extends Model
{
    /** @use HasFactory<Factory<static>> */
    use Auditable, HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit',
        'credit',
        'description',
        'vat_rate_id',
        'vat_amount',
        'vat_type',
        'vat_figure',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'vat_type' => VatEntryType::class,
        ];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<VatRate, $this> */
    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class, 'vat_rate_id', 'uuid');
    }
}
