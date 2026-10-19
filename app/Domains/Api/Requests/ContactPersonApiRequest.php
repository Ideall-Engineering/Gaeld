<?php

namespace App\Domains\Api\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a contact person sent through the API. Creating needs both
 * names; an update changes only the fields that are sent.
 */
class ContactPersonApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'first_name' => [$required, 'string', 'max:255'],
            'last_name' => [$required, 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
