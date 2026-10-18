<?php

namespace App\Domains\Projects\Requests;

use App\Domains\Accounting\Rules\ValidCostCenterParent;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Policies\ProjectPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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

        // Spec 010: the code is optional. A new job gets the next free
        // P<yy>-<nn>, a new group B-<NAME>; an edit keeps the code it had.
        if (in_array($this->input('code'), [null, ''], true)) {
            $project = $this->route('project');
            $this->merge(['code' => $project instanceof Project
                ? $project->code
                : self::nextCode((string) $this->input('kind'), (string) $this->input('name'))]);
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
     * The next free code for a new job or group of the current organization.
     */
    public static function nextCode(string $kind, string $name): string
    {
        $taken = Project::query()->pluck('code')->map(fn ($code) => strtoupper((string) $code))->flip();

        if ($kind === ProjectKind::Area->value) {
            $base = 'B-'.(Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->limit(20, '')->value() ?: 'PROJEKT');
            $code = $base;
            for ($n = 2; $taken->has($code); $n++) {
                $code = "{$base}-{$n}";
            }

            return $code;
        }

        $prefix = 'P'.now()->format('y').'-';
        $next = $taken->keys()
            ->filter(fn (string $code) => str_starts_with($code, $prefix) && ctype_digit(substr($code, strlen($prefix))))
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        do {
            $next++;
            $code = $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
        } while ($taken->has($code));

        return $code;
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
            // Stage 5: how the project's value is shared; empty clears it.
            'shares' => [
                'sometimes',
                'array',
                'max:10',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $total = array_sum(array_map(fn ($s) => (float) ($s['share'] ?? 0), (array) $value));
                    if ($value !== [] && abs($total - 100) > 0.0001) {
                        $fail(__('app.acquisition_shares_total'));
                    }
                },
            ],
            'shares.*.employee_id' => ['required', 'uuid', 'distinct', Rule::exists('employees', 'id')->where('organization_id', $orgId)],
            'shares.*.share' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }
}
