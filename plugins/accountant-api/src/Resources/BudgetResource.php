<?php

namespace Plugins\AccountantApi\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No `@mixin`/import of the core Budget model: fields are read through
 * dynamic property access, keeping the resource decoupled from the core
 * model — same rule as {@see JournalCorrectionResource}.
 *
 * There is no `id` field on purpose. A budget is addressed by its natural
 * key; exposing the internal auto-increment id would publish an identifier
 * the rest of the API never uses.
 */
class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account_code' => $this->account?->code,
            'account_name' => $this->account?->name,
            'fiscal_year' => $this->fiscal_year,
            'monthly_amount' => $this->monthly_amount,
            'annual_amount' => $this->annualAmount(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Twelve monthly targets, formatted like every other money field in the
     * API: a decimal string with two places, never a float.
     */
    private function annualAmount(): string
    {
        return bcmul((string) $this->monthly_amount, '12', 2);
    }
}
