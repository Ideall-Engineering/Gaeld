<?php

namespace App\Domains\Api\Resources;

use App\Domains\Invoicing\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
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
            'is_expired' => $this->isExpired(),
            'title' => $this->title,
            'tax_treatment' => $this->tax_treatment->value,
            'customer' => new ContactResource($this->whenLoaded('customer')),
            'issue_date' => $this->issue_date->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'subtotal' => $this->subtotal,
            'vat_amount' => $this->vat_amount,
            'total' => $this->total,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'payment_terms' => $this->payment_terms,
            'project_id' => $this->cost_center_id,
            'invoice_id' => $this->invoice_id,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'lines' => QuoteLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
