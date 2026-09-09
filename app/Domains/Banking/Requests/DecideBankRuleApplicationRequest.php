<?php

namespace App\Domains\Banking\Requests;

use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideBankRuleApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();

        return [
            // Omitting a field means "as proposed" — the engine fills it in, so
            // confirming needs no payload at all.
            'account_code' => [
                'nullable', 'string', 'max:10',
                Rule::exists('accounts', 'code')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'tax_treatment' => ['nullable', Rule::enum(ExpenseTaxTreatment::class)],
            'vat_rate_id' => [
                'nullable', 'integer',
                Rule::exists('vat_rates', 'id')->where('organization_id', $organizationId),
            ],
            'rejected' => ['sometimes', 'boolean'],
        ];
    }
}
