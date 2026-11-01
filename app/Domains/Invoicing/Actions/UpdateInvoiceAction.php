<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\DTOs\UpdateInvoiceData;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceInstallment;
use App\Domains\Invoicing\Services\InstallmentPlanService;
use App\Domains\Invoicing\Support\DocumentContactPerson;
use Illuminate\Support\Facades\DB;

/**
 * Updates a draft invoice and re-syncs its line items.
 */
class UpdateInvoiceAction
{
    public function __construct(
        private SyncInvoiceLinesAction $syncInvoiceLines,
        private InstallmentPlanService $installmentPlans,
    ) {}

    public function execute(Invoice $invoice, UpdateInvoiceData $data): Invoice
    {
        if (! $invoice->status->isEditable()) {
            throw new InvalidInvoiceStateException('Only draft invoices can be updated.');
        }

        $invoiceData = [
            'customer_id' => $data->customerId,
            'number' => $data->number,
            'issue_date' => $data->issueDate,
            'due_date' => $data->dueDate,
            'currency' => $data->currency,
            'notes' => $data->notes,
            'payment_terms' => $data->paymentTerms,
            'delivery_note' => $data->deliveryNote,
            'intro_text' => $data->introText,
        ];

        $organizationId = $invoice->getAttribute('organization_id');
        $storedTaxTreatment = $invoice->getRawOriginal('tax_treatment');
        if ($data->taxTreatment !== InvoiceTaxTreatment::Standard
            || ($storedTaxTreatment !== null && $storedTaxTreatment !== InvoiceTaxTreatment::Standard->value)) {
            $invoiceData['tax_treatment'] = $data->taxTreatment;
        }

        if (is_string($organizationId) && $organizationId !== '') {
            $customer = $data->customerId === null
                ? null
                : Contact::withoutGlobalScope('organization')
                    ->where('organization_id', $organizationId)
                    ->findOrFail($data->customerId);
            $data->taxTreatment->validateCustomer($customer);
            $person = DocumentContactPerson::of($customer, $data->contactPersonId);
            $invoiceData['customer_snapshot'] = $customer?->toInvoiceSnapshot($person);
            $invoiceData['contact_person_id'] = $person?->id;
        }

        // One transaction, so a plan the new lines no longer fit leaves the
        // draft as it was (spec 013).
        return DB::transaction(function () use ($invoice, $invoiceData, $data): Invoice {
            $invoice->update($invoiceData);

            if ($data->taxTreatment->appliesSwissVat()) {
                $this->syncInvoiceLines->replace($invoice, $data->lines);
            } else {
                $this->syncInvoiceLines->replace($invoice, $data->lines, false);
            }

            $invoice->recalculate();

            // Without a new plan the stored one is laid over the new total
            // again, so its last rate keeps carrying the rest.
            $rates = $data->installments ?? $invoice->installments()->get()
                ->map(fn (InvoiceInstallment $rate) => ['due_date' => $rate->due_date->toDateString(), 'amount' => (string) $rate->amount])
                ->all();
            if ($rates !== [] || $invoice->installments()->exists()) {
                $this->installmentPlans->sync($invoice, $rates);
            }

            return $invoice->load('lines');
        });
    }
}
