<?php

namespace App\Domains\Invoicing\Models;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line of a quote; calculated exactly like an {@see InvoiceLine} so that the
 * invoice made from an accepted quote comes to the same total.
 *
 * @property int $id
 * @property string $quote_id
 * @property InvoiceLineType $type
 * @property string|null $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $amount
 * @property string|null $discount_type
 * @property int|null $vat_rate_id
 * @property string|null $vat_amount
 * @property int $sort_order
 * @property-read Quote $quote
 * @property-read VatRate|null $vatRate
 */
class QuoteLine extends Model
{
    protected $fillable = [
        'quote_id',
        'type',
        'discount_type',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'vat_rate_id',
        'vat_amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceLineType::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return BelongsTo<VatRate, $this> */
    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function calculate(): void
    {
        if (! $this->type->hasAmount()) {
            $this->quantity = '0.00';
            $this->unit_price = '0.00';
            $this->amount = '0.00';
            $this->vat_amount = '0.00';
            $this->vat_rate_id = null;

            return;
        }

        $this->amount = Money::multiply2($this->quantity, $this->unit_price);
        $this->vat_amount = $this->vatRate ? Money::percentage($this->amount, (string) $this->vatRate->rate) : '0.00';
    }
}
