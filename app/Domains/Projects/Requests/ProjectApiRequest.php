<?php

namespace App\Domains\Projects\Requests;

use App\Domains\Accounting\Rules\ValidCostCenterParent;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or change a project over the API (spec 009, User Story 6).
 *
 * The API names a parent by its code and a customer by its public UUID,
 * like the rest of the API; {@see projectAttributes()} turns them into ids.
 */
class ProjectApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        // Bound before the API organization was known; a foreign project
        // must look like a missing one.
        abort_if($project instanceof Project && $project->organization_id !== app(CurrentOrganization::class)->id(), 404);

        return $project instanceof Project
            ? ($this->user()?->can('update', $project) ?? false)
            : ($this->user()?->can('create', Project::class) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $orgId = app(CurrentOrganization::class)->id();
        $project = $this->route('project');
        $current = $project instanceof Project ? $project : null;
        $required = $current === null ? 'required' : 'sometimes';

        $unique = Rule::unique('cost_centers', 'code')->where('organization_id', $orgId);
        if ($current !== null) {
            $unique->ignore($current->id);
        }

        return [
            'code' => [$required, 'string', 'max:30', $unique],
            'name' => [$required, 'string', 'max:255'],
            'parent_code' => ['sometimes', 'nullable', 'string', Rule::exists('cost_centers', 'code')->where('organization_id', $orgId)],
            'kind' => ['sometimes', Rule::in(ProjectKind::values())],
            'status' => ['sometimes', Rule::in(ProjectStatus::values())],
            'contact_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('contacts', 'uuid')->where('organization_id', $orgId)],
            'responsible_employee_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('employees', 'id')->where('organization_id', $orgId)],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'billing_month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'expected_amount' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,11}(?:\.\d{1,2})?$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if (is_string($this->input('parent_code'))) {
            $this->merge(['parent_code' => strtoupper(trim($this->input('parent_code')))]);
        }
    }

    /**
     * The validated payload with codes and UUIDs replaced by ids.
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        $validated = $this->validated();
        if (array_key_exists('billing_month', $validated) && $validated['billing_month'] !== null) {
            $validated['billing_month'] .= '-01';
        }
        $orgId = app(CurrentOrganization::class)->id();

        if (array_key_exists('parent_code', $validated)) {
            $parentId = $validated['parent_code'] === null ? null : Project::query()->where('code', $validated['parent_code'])->value('id');
            $current = $this->route('project');
            $parentCheck = Validator::make(
                ['parent_id' => $parentId],
                ['parent_id' => [new ValidCostCenterParent($orgId, $current instanceof Project ? $current : null)]],
            );
            if ($parentCheck->fails()) {
                throw ValidationException::withMessages(['parent_code' => 'The parent would create a circular hierarchy.']);
            }
            $validated['parent_id'] = $parentId;
            unset($validated['parent_code']);
        }

        if (array_key_exists('contact_id', $validated)) {
            $validated['contact_id'] = $validated['contact_id'] === null
                ? null
                : Contact::query()->where('uuid', $validated['contact_id'])->value('id');
        }

        return $validated;
    }
}
