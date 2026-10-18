<?php

namespace App\Domains\Projects\Queries;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectShare;
use Illuminate\Support\Collection;

/**
 * Read models for project screens and pickers (spec 009).
 */
class ProjectQuery
{
    /**
     * All projects in tree order (each parent followed by its children),
     * with their depth, for lists and pickers.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tree(bool $activeOnly = false, bool $withShares = false): array
    {
        $shares = $withShares
            ? ProjectShare::query()->get()->groupBy('cost_center_id')
            : collect();

        $projects = Project::query()
            ->with(['contact:id,name', 'responsible:id,first_name,last_name'])
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get();

        $byParent = $projects->groupBy(fn (Project $p) => $p->parent_id ?? 0);
        $known = $projects->pluck('id')->flip();
        $rows = [];

        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent, $shares, $withShares): void {
            foreach ($byParent->get($parentId, collect()) as $project) {
                $rows[] = self::present($project, $depth, $withShares ? $shares->get($project->id, collect()) : null);
                if ($depth < 32) {
                    $walk($project->id, $depth + 1);
                }
            }
        };

        $walk(0, 0);

        // Children of an inactive parent still appear when only active
        // projects are listed; they hang at the top level then.
        foreach ($projects as $project) {
            if ($project->parent_id !== null && ! $known->has($project->parent_id)) {
                $rows[] = self::present($project, 0, $withShares ? $shares->get($project->id, collect()) : null);
                $walk($project->id, 1);
            }
        }

        return $rows;
    }

    /**
     * Options for a project picker: active projects only, in tree order.
     *
     * Each carries its kind and the name of the project (top-most area)
     * it belongs to, so pickers can group jobs by project (spec 010).
     *
     * @return array<int, array{id: int, code: string, name: string, depth: int, kind: string, group: string|null}>
     */
    public static function options(): array
    {
        $rows = self::tree(activeOnly: true);
        $byId = array_column($rows, null, 'id');

        return array_map(function (array $row) use ($byId) {
            $group = null;
            $cursor = $row['parent_id'] !== null ? ($byId[$row['parent_id']] ?? null) : null;
            for ($depth = 0; $cursor !== null && $depth < 32; $depth++) {
                if ($cursor['kind'] === 'area') {
                    $group = $cursor['name'];
                }
                $cursor = $cursor['parent_id'] !== null ? ($byId[$cursor['parent_id']] ?? null) : null;
            }

            return [
                'id' => $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'depth' => $row['depth'],
                'kind' => $row['kind'],
                'group' => $row['kind'] === 'area' ? $row['name'] : $group,
            ];
        }, $rows);
    }

    /**
     * Options for a person picker: active employees of the organization.
     *
     * @return Collection<int, array{id: string, name: string}>
     */
    public static function employeeOptions(): Collection
    {
        return Employee::query()
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => trim("{$e->first_name} {$e->last_name}")]);
    }

    /**
     * What the cost block form needs: expense accounts and kinds (spec 010,
     * the form sits on the jobs page and the people page).
     *
     * @return array{accounts: Collection<int, array{id: int, code: string, name: string}>, kinds: array<int, array{value: string, label: string}>}
     */
    public static function blockOptions(): array
    {
        return [
            'accounts' => Account::query()
                ->where('type', AccountType::Expense->value)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $a) => ['id' => (int) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->display_name]),
            'kinds' => array_map(fn (BudgetGroupKind $k) => ['value' => $k->value, 'label' => (string) __('app.budget_group_kind_'.$k->value)], BudgetGroupKind::cases()),
        ];
    }

    /**
     * @param  Collection<int, ProjectShare>|null  $shares  null leaves the key out
     * @return array<string, mixed>
     */
    private static function present(Project $project, int $depth, ?Collection $shares = null): array
    {
        return [
            ...($shares === null ? [] : ['shares' => $shares->map(fn (ProjectShare $s) => ['employee_id' => $s->employee_id, 'share' => (string) (float) $s->share])->values()->all()]),
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'parent_id' => $project->parent_id,
            'depth' => $depth,
            'kind' => $project->kind->value,
            'status' => $project->status->value,
            'is_active' => $project->is_active,
            'contact_id' => $project->contact_id,
            'contact_name' => $project->contact?->name,
            'responsible_employee_id' => $project->responsible_employee_id,
            'responsible_name' => $project->responsible !== null
                ? trim("{$project->responsible->first_name} {$project->responsible->last_name}")
                : null,
            'target_date' => $project->target_date?->toDateString(),
            'billing_month' => $project->billing_month?->format('Y-m'),
            'payment_expected_on' => $project->payment_expected_on?->toDateString(),
            'expected_amount' => $project->expected_amount,
            'notes' => $project->notes,
        ];
    }
}
