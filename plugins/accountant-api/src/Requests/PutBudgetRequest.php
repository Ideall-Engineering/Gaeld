<?php

namespace Plugins\AccountantApi\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a budget upsert.
 *
 * `account_code` is not validated here: it is a path segment resolved by
 * BudgetBridge against the token's organization, and an unknown or inactive
 * code yields a 404 rather than a validation error. `fiscal_year` is also a
 * path segment but is a value being stored, so it stays a 422.
 *
 * The `fiscal_year` and `monthly_amount` rules intentionally mirror the web
 * request `StoreBudgetRequest` in the Accounting domain; keep the two in
 * step. They are duplicated rather than shared because the module boundary
 * test forbids a Fachdomain import from Requests/ — note that this must
 * stay prose, since a docblock FQN would be rewritten into a real `use`
 * statement by Pint and break that very rule.
 */
class PutBudgetRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'fiscal_year' => $this->route('fiscal_year'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2099'],
            'monthly_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
