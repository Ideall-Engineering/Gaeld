<?php

namespace App\Domains\Projects\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\AcquisitionStatus;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An article bought for one or more people (spec 009, stage 4).
 *
 * @property int $id
 * @property string $organization_id
 * @property string $item
 * @property string|null $category
 * @property string|null $supplier
 * @property Carbon $purchased_on
 * @property string $quantity
 * @property string $unit_price
 * @property string $gross_amount
 * @property string|null $vat_rate
 * @property string $net_amount
 * @property AcquisitionStatus $status
 * @property Carbon|null $return_until
 * @property Carbon|null $warranty_until
 * @property string|null $journal_entry_id
 * @property string|null $notes
 * @property-read Collection<int, Employee> $people
 * @property-read JournalEntry|null $journalEntry
 */
class Acquisition extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'item',
        'category',
        'supplier',
        'purchased_on',
        'quantity',
        'unit_price',
        'gross_amount',
        'vat_rate',
        'net_amount',
        'status',
        'return_until',
        'warranty_until',
        'journal_entry_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchased_on' => 'date',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'status' => AcquisitionStatus::class,
            'return_until' => 'date',
            'warranty_until' => 'date',
        ];
    }

    /** @return BelongsToMany<Employee, $this> */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'acquisition_people')->withPivot('share')->withTimestamps();
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
