<?php

namespace App\Domains\Api\Resources;

use App\Domains\Accounting\Models\TransactionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TransactionLine */
class TransactionLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account' => [
                'id' => $this->account?->uuid,
                'code' => $this->account?->code,
                'name' => $this->account?->display_name,
                'type' => $this->account?->type->value,
            ],
            'account_code' => $this->account?->code,
            'debit' => (string) $this->debit,
            'credit' => (string) $this->credit,
            'description' => $this->description,
            'vat_rate_id' => $this->vat_rate_id,
            'vat_amount' => $this->vat_amount === null ? null : (string) $this->vat_amount,
            'vat_type' => $this->vat_type?->value,
            'vat_figure' => $this->vat_figure,
        ];
    }
}
