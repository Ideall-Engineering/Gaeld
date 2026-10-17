<?php

namespace App\Domains\Projects\Resources;

use App\Domains\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'parent_code' => $this->parent?->code,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'is_active' => $this->is_active,
            'contact_id' => $this->contact?->uuid,
            'responsible_employee_id' => $this->responsible_employee_id,
            'target_date' => $this->target_date?->toDateString(),
            'billing_month' => $this->billing_month?->format('Y-m'),
            'expected_amount' => $this->expected_amount,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
