<?php

namespace App\Domains\Api\Requests;

use App\Domains\Api\Support\GrantedTokenAbilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePersonalTokenSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['array'],
            'abilities.*' => ['string', Rule::in($this->allowedAbilities())],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    /** @return array<int, string> */
    private function allowedAbilities(): array
    {
        // The catalogue narrowed to what this person holds: a token must not be
        // able to name a permission its creator does not have.
        return GrantedTokenAbilities::accepted($this->user());
    }
}
