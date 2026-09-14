<?php

namespace Plugins\AccountantApi\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One account's budget versus actual. The wrapped value is already a plain
 * array assembled by BudgetBridge out of the core report, so there is no
 * core model to decouple from here.
 */
class BudgetVarianceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'account_code' => $row['account_code'],
            'account_name' => $row['account_name'],
            'account_type' => $row['account_type'],
            'actual_amount' => $row['actual_amount'],
            'budget_amount' => $row['budget_amount'],
            'variance' => $row['variance'],
            'variance_percentage' => $row['variance_percentage'],
        ];
    }
}
