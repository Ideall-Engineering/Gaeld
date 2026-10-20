<?php

namespace App\Domains\Api\Resources;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\RecurringInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecurringInvoice */
class RecurringInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $template = (array) $this->template_data;
        $rates = VatRate::query()->whereIn('id', array_filter(array_column((array) ($template['lines'] ?? []), 'vat_rate_id')))->pluck('uuid', 'id');

        /** @var Contact|null $customer */
        $customer = $this->resource->customer;

        return [
            'id' => $this->uuid,
            'customer' => $customer === null ? null : ['id' => $customer->uuid, 'name' => $customer->name],
            'frequency' => $this->frequency->value,
            'next_issue_date' => $this->next_issue_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => $this->is_active,
            'currency' => $template['currency'] ?? 'CHF',
            'notes' => $template['notes'] ?? null,
            'payment_terms' => $template['payment_terms'] ?? null,
            'contact_person_id' => $template['contact_person_id'] ?? null,
            'lines' => array_map(fn (array $line) => [
                'description' => $line['description'] ?? '',
                'quantity' => (string) ($line['quantity'] ?? '0'),
                'unit' => $line['unit'] ?? null,
                'unit_price' => (string) ($line['unit_price'] ?? '0'),
                'vat_rate_id' => isset($line['vat_rate_id']) ? ($rates[$line['vat_rate_id']] ?? null) : null,
            ], array_values((array) ($template['lines'] ?? []))),
        ];
    }
}
