<?php

namespace App\Domains\Projects\Models;

use App\Domains\Invoicing\Models\Invoice;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The project an invoice belongs to (spec 009, stage 2). One row per
 * invoice, beside it, so archived invoices stay untouched.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property int $cost_center_id
 * @property-read Invoice $invoice
 * @property-read Project $project
 */
class InvoiceProject extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'cost_center_id',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'cost_center_id');
    }
}
