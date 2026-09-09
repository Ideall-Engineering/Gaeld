<?php

namespace App\Domains\Automation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAutomationSettingRequest extends FormRequest
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
        return [
            'automation' => ['required', 'string', 'max:64'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
