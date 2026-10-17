<?php

namespace App\Domains\Projects\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Domains\Projects\Models\BudgetGroup;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A cost block (spec 009, stage 3): an account belongs to at most one block
 * per year, so no cost is counted twice.
 */
class BudgetGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $group = $this->route('budgetGroup');
        if ($group instanceof BudgetGroup) {
            $this->merge(['year' => $group->year]);
        }
        if ($this->input('plan_amount') === '') {
            $this->merge(['plan_amount' => null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $orgId = app(CurrentOrganization::class)->id();
        $group = $this->route('budgetGroup');
        $groupId = $group instanceof BudgetGroup ? $group->id : 0;

        $unique = Rule::unique('budget_groups', 'name')
            ->where('organization_id', $orgId)
            ->where('year', (int) $this->input('year'))
            ->ignore($groupId);

        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'name' => ['required', 'string', 'max:120', $unique],
            'kind' => ['required', Rule::in(BudgetGroupKind::values())],
            'plan_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'account_ids' => ['array', 'max:100'],
            'account_ids.*' => [
                'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $orgId),
                function (string $attribute, mixed $value, Closure $fail) use ($orgId, $groupId): void {
                    $taken = DB::table('budget_group_accounts')
                        ->join('budget_groups', 'budget_groups.id', '=', 'budget_group_accounts.budget_group_id')
                        ->where('budget_groups.organization_id', $orgId)
                        ->where('budget_groups.year', (int) $this->input('year'))
                        ->where('budget_groups.id', '<>', $groupId)
                        ->where('budget_group_accounts.account_id', (int) $value)
                        ->value('budget_groups.name');
                    if ($taken !== null) {
                        $fail(__('app.budget_group_account_taken', ['group' => $taken]));
                    }
                },
            ],
        ];
    }
}
