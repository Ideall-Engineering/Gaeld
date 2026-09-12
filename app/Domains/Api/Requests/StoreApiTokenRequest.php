<?php

namespace App\Domains\Api\Requests;

use App\Domains\Api\Support\GrantedTokenAbilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends FormRequest
{
    /**
     * Personal token creation — only an authenticated user may create their own token.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'abilities' => 'array',
            // Narrowed to what the creator holds — see GrantedTokenAbilities.
            'abilities.*' => ['string', Rule::in(GrantedTokenAbilities::accepted($this->user()))],
            'expires_in_days' => 'nullable|integer|min:1|max:365',
        ];
    }
}
