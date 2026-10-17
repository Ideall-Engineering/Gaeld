<?php

namespace App\Domains\Api\Resources;

use App\Domains\Accounting\Models\TransactionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TransactionLine */
class TransactionLineResource extends JsonResource
{
    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Spec 009: only where projects are switched on (the allocations
            // are loaded then); elsewhere the answer stays as it was.
            $this->mergeWhen($this->relationLoaded('allocations'), fn () => [
                'line_id' => $this->id,
                'allocations' => $this->allocations->sortBy('id')->values()->map(fn ($allocation) => [
                    'project_code' => $allocation->project?->code,
                    'employee_id' => $allocation->employee_id,
                    'share' => (string) $allocation->share,
                ])->all(),
            ]),
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
