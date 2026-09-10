<?php

namespace Plugins\AccountantApi\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugins\AccountantApi\Requests\Concerns\ValidatesJournalLinePayload;

/**
 * `POST /api/v1/journal-entries/{original}/corrections`. `reason` and
 * `correction_date` are always required; the replacement payload
 * (date/reference/description/lines) is entirely optional — omitting it
 * copies the original in full, per plan.md.
 *
 * Real authorization (is this user allowed to correct *this* entry) happens
 * in the controller via CoreBridge, mirroring
 * `App\Domains\Api\Requests\JournalEntryActionApiRequest` — this class only
 * confirms a user is present, it cannot check an ability against a specific
 * entry without the route-bound model.
 *
 * `App\Domains\Organizations\...` is platform tenancy resolution, not
 * Fachdomain — see the boundary test's docblock for why it, `App\Domains\
 * Users\...`, and `App\Domains\Api\...` are the accepted exceptions.
 */
class PrepareJournalCorrectionRequest extends FormRequest
{
    use ValidatesJournalLinePayload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function hasReplacementPayload(): bool
    {
        return $this->has('lines');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currentOrganization = app(CurrentOrganization::class);
        $organizationId = $currentOrganization->isBound() ? $currentOrganization->id() : '0';

        $rules = [
            'reason' => ['required', 'string', 'max:1000'],
            'correction_date' => ['required', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'lines' => ['sometimes', 'array', 'max:100'],
        ];

        if ($this->hasReplacementPayload()) {
            $rules['lines'][] = $this->usesLineShorthand() ? 'min:1' : 'min:2';

            return [...$rules, ...$this->linePayloadRules($organizationId)];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        if (! $this->hasReplacementPayload()) {
            return [];
        }

        return [fn (Validator $validator) => $this->addLineBalanceErrors($validator)];
    }
}
