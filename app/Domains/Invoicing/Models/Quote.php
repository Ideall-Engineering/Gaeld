<?php

namespace App\Domains\Invoicing\Models;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Models\Concerns\AddressesCustomer;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Project;
use App\Support\Money;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Quote (offer) to a customer: the same lines and totals as an invoice, but
 * nothing is booked. An accepted quote becomes an invoice draft.
 *
 * @property string $id
 * @property string $organization_id
 * @property int|null $customer_id
 * @property array{name: string, email: string|null, address: string|null, postal_code: string|null, city: string|null, country: string, country_name?: string|null, salutation?: string|null, vat_number: string|null, contact_person?: array{name: string, department: string|null, position: string|null, email: string|null}}|null $customer_snapshot
 * @property string $number
 * @property QuoteStatus $status
 * @property InvoiceTaxTreatment $tax_treatment
 * @property Carbon $issue_date
 * @property Carbon|null $valid_until
 * @property string|null $title
 * @property string $subtotal
 * @property string $vat_amount
 * @property string $total
 * @property string $currency
 * @property string|null $notes
 * @property string|null $payment_terms
 * @property string|null $contact_person_id
 * @property string|null $delivery_note
 * @property string|null $intro_text
 * @property-read ContactPerson|null $contactPerson
 * @property int|null $cost_center_id
 * @property string|null $invoice_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Contact|null $customer
 * @property-read Organization $organization
 * @property-read Invoice|null $invoice
 * @property-read Project|null $project
 * @property-read Collection<int, QuoteLine> $lines
 */
class Quote extends Model
{
    use AddressesCustomer, Auditable, BelongsToOrganization, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $with = ['customer'];

    protected $attributes = [
        'status' => 'draft',
        'tax_treatment' => 'standard',
    ];

    protected $fillable = [
        'organization_id',
        'customer_id',
        'customer_snapshot',
        'contact_person_id',
        'number',
        'status',
        'tax_treatment',
        'issue_date',
        'valid_until',
        'title',
        'subtotal',
        'vat_amount',
        'total',
        'currency',
        'notes',
        'payment_terms',
        'delivery_note',
        'intro_text',
        'cost_center_id',
        'invoice_id',
        'sent_at',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => QuoteStatus::class,
            'tax_treatment' => InvoiceTaxTreatment::class,
            'customer_snapshot' => 'array',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<ContactPerson, $this> */
    public function contactPerson(): BelongsTo
    {
        return $this->belongsTo(ContactPerson::class);
    }

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

    /** @return HasMany<QuoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isDraftDocument(): bool
    {
        return $this->status === QuoteStatus::Draft;
    }

    /** A sent quote past its validity date; it can still be accepted. */
    public function isExpired(): bool
    {
        return $this->status === QuoteStatus::Sent
            && $this->valid_until !== null
            && $this->valid_until->isBefore(now()->startOfDay());
    }

    public function recalculate(): void
    {
        $totals = $this->lines()->reorder()
            ->selectRaw("SUM(CASE WHEN type = 'discount' THEN -amount ELSE amount END) as total_amount")
            ->selectRaw("SUM(CASE WHEN type = 'discount' THEN -vat_amount ELSE vat_amount END) as total_vat")
            ->first();

        $this->subtotal = $totals->total_amount ?? '0';
        $this->vat_amount = $totals->total_vat ?? '0';
        $this->total = Money::add($this->subtotal, $this->vat_amount);
        $this->save();
    }
}
