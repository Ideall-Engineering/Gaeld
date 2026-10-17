<?php

namespace App\Domains\Projects\Requests;

use App\Domains\Accounting\Rules\ValidCostCenterParent;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Policies\ProjectPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or change a project (spec 009). Authorization happens in the
 * controller through {@see ProjectPolicy}.
 */
class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }

        // A month field sends YYYY-MM; the first of that month is stored.
        if (is_string($this->input('billing_month')) && preg_match('/^\d{4}-\d{2}$/', $this->input('billing_month')) === 1) {
            $this->merge(['billing_month' => $this->input('billing_month').'-01']);
        }

        foreach (['parent_id', 'contact_id', 'responsible_employee_id', 'target_date', 'billing_month', 'expected_amount'] as $field) {
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
        $project = $this->route('project');
        $current = $project instanceof Project ? $project : null;

        $unique = Rule::unique('cost_centers', 'code')->where('organization_id', $orgId);
        if ($current !== null) {
            $unique->ignore($current->id);
        }

        return [
            'code' => ['required', 'string', 'max:30', $unique],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', new ValidCostCenterParent($orgId, $current)],
            'kind' => ['required', Rule::in(ProjectKind::values())],
            'status' => ['required', Rule::in(ProjectStatus::values())],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('organization_id', $orgId)],
            'responsible_employee_id' => ['nullable', 'uuid', Rule::exists('employees', 'id')->where('organization_id', $orgId)],
            'target_date' => ['nullable', 'date'],
            'billing_month' => ['nullable', 'date'],
            'expected_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
