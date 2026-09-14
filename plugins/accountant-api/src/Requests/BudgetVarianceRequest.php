<?php

namespace Plugins\AccountantApi\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a budget-versus-actual query.
 *
 * `fiscal_year` is required and selects the targets to compare against.
 * `from` and `to` are optional and narrow the comparison to part of that
 * year — the target is then prorated by month. Both must lie inside the
 * requested year, because the core report derives the fiscal year from the
 * year of the start date; a period straddling two years would silently
 * compare against the wrong targets.
 */
class BudgetVarianceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2099'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $year = (int) $this->input('fiscal_year');

            foreach (['from', 'to'] as $field) {
                $value = $this->input($field);
                if ($value === null || $value === '') {
                    continue;
                }

                if ((int) substr((string) $value, 0, 4) !== $year) {
                    $validator->errors()->add($field, "The {$field} date must fall within fiscal year {$year}.");
                }
            }
        });
    }

    /** @return array{0: string, 1: string} */
    public function period(): array
    {
        $year = (int) $this->input('fiscal_year');

        return [
            (string) ($this->input('from') ?: "{$year}-01-01"),
            (string) ($this->input('to') ?: "{$year}-12-31"),
        ];
    }
}
