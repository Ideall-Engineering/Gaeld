<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceNumberGenerator;
use App\Domains\Invoicing\Support\DocumentContactPerson;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new draft invoice by duplicating an existing one.
 */
class DuplicateInvoiceAction
{
    public function __construct(
        private SyncInvoiceLinesAction $syncInvoiceLines,
        private InvoiceNumberGenerator $numberGenerator,
    ) {}

    public function execute(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            // The copy addresses the customer as it is today, not as the
            // original was sent: current address and contact person.
            $customer = $invoice->customer;
            $person = DocumentContactPerson::of($customer, $invoice->contact_person_id)
                ?? DocumentContactPerson::primaryOf($customer);

            $newInvoice = Invoice::create([
                'organization_id' => $invoice->organization_id,
                'customer_id' => $invoice->customer_id,
                'customer_snapshot' => $customer?->toInvoiceSnapshot($person) ?? $invoice->customer_snapshot,
                'contact_person_id' => $person?->id,
                'delivery_note' => $invoice->delivery_note,
                'intro_text' => $invoice->intro_text,
                'number' => $this->numberGenerator->next($invoice->organization_id),
                'status' => InvoiceStatus::Draft,
                'tax_treatment' => $invoice->tax_treatment ?? InvoiceTaxTreatment::Standard,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $invoice->currency,
                'notes' => $invoice->notes,
                'payment_terms' => $invoice->payment_terms,
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
            ]);

            $this->syncInvoiceLines->create($newInvoice, $invoice->lines->map(
                fn ($line) => new InvoiceLineData(
                    description: $line->description,
                    quantity: $line->quantity,
                    unitPrice: $line->unit_price,
                    type: $line->type,
                    discountType: $line->discount_type,
                    vatRateId: $line->vat_rate_id === null ? null : (string) $line->vat_rate_id,
                    sortOrder: $line->sort_order,
                    unit: $line->unit,
                )
            )->all(), ($invoice->tax_treatment ?? InvoiceTaxTreatment::Standard)->appliesSwissVat());

            $newInvoice->recalculate();

            return $newInvoice->load('lines');
        });
    }
}
