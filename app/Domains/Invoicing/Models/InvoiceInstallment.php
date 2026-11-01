<?php

namespace App\Domains\Invoicing\Models;

use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rate of an invoice's payment plan (spec 013), with its own due date,
 * amount and QR reference.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property int $position
 * @property Carbon $due_date
 * @property string $amount
 * @property string|null $qr_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 */
class InvoiceInstallment extends Model
{
    /** @use HasFactory<Factory<static>> */
    use Auditable, BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'position',
        'due_date',
        'amount',
        'qr_reference',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
