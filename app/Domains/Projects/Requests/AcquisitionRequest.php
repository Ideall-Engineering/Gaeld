<?php

namespace App\Domains\Projects\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\AcquisitionStatus;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An article bought for people (spec 009, stage 4).
 */
class AcquisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['category', 'supplier', 'vat_rate', 'net_amount', 'return_until', 'warranty_until', 'journal_entry_id', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $orgId = app(CurrentOrganization::class)->id();

        return [
            'item' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'supplier' => ['nullable', 'string', 'max:120'],
            'purchased_on' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'net_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'status' => ['required', Rule::in(AcquisitionStatus::values())],
            'return_until' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'date'],
            'journal_entry_id' => ['nullable', 'uuid', Rule::exists('journal_entries', 'id')->where('organization_id', $orgId)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'people' => [
                'required',
                'array',
                'min:1',
                'max:10',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $total = array_sum(array_map(fn ($p) => (float) ($p['share'] ?? 0), (array) $value));
                    if (abs($total - 100) > 0.0001) {
                        $fail(__('app.acquisition_shares_total'));
                    }
                },
            ],
            'people.*.employee_id' => ['required', 'uuid', 'distinct', Rule::exists('employees', 'id')->where('organization_id', $orgId)],
            'people.*.share' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }
}
