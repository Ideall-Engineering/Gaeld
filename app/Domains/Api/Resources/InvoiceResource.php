<?php

namespace App\Domains\Api\Resources;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Projects\Support\InvoiceProjectInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'type' => $this->type->value,
            'tax_treatment' => $this->tax_treatment->value,
            'related_invoice_id' => $this->related_invoice_id,
            'customer' => new ContactResource($this->whenLoaded('customer')),
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'vat_amount' => $this->vat_amount,
            'total' => $this->total,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'payment_terms' => $this->payment_terms,
            'contact_person_id' => $this->contact_person_id,
            'project_id' => InvoiceProjectInput::currentFor($this->resource),
            'has_justificatif' => filled($this->justificatif_path),
            'delivery_note' => $this->delivery_note,
            'intro_text' => $this->intro_text,
            'amount_paid' => $this->amountPaid(),
            'amount_due' => $this->amountDue(),
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'payments' => InvoicePaymentResource::collection($this->whenLoaded('payments')),
            'journal_entry' => new JournalEntryResource($this->whenLoaded('journalEntry')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
