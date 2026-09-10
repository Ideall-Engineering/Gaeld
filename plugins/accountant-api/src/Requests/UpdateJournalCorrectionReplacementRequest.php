<?php

namespace Plugins\AccountantApi\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugins\AccountantApi\Requests\Concerns\ValidatesJournalLinePayload;

/**
 * `PUT /api/v1/journal-corrections/{correction}/replacement`. Uses the same
 * extracted validation rules and mapper as journal creation (plan.md).
 */
class UpdateJournalCorrectionReplacementRequest extends FormRequest
{
    use ValidatesJournalLinePayload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currentOrganization = app(CurrentOrganization::class);
        $organizationId = $currentOrganization->isBound() ? $currentOrganization->id() : '0';

        $rules = [
            'date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'max:100'],
        ];

        $rules['lines'][] = $this->usesLineShorthand() ? 'min:1' : 'min:2';

        return [...$rules, ...$this->linePayloadRules($organizationId)];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addLineBalanceErrors($validator)];
    }
}
