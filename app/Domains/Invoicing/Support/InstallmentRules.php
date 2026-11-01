<?php

namespace App\Domains\Invoicing\Support;

use App\Domains\Invoicing\Services\InstallmentPlanService;

/**
 * Request rules for the rates of a payment plan (spec 013). Only the shape
 * is checked here; InstallmentPlanService holds the rules of the plan.
 */
final class InstallmentRules
{
    /** @return array<string, string> */
    public static function rules(): array
    {
        return [
            'installments' => 'nullable|array|max:'.InstallmentPlanService::MAX_RATES,
            'installments.*.due_date' => 'required|date',
            'installments.*.amount' => 'nullable|numeric',
        ];
    }
}
