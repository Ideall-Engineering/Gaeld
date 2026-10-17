<?php

namespace App\Domains\Projects\Queries;

use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Models\Project;
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
    public static function tree(bool $activeOnly = false): array
    {
        $projects = Project::query()
            ->with(['contact:id,name', 'responsible:id,first_name,last_name'])
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get();

        $byParent = $projects->groupBy(fn (Project $p) => $p->parent_id ?? 0);
        $known = $projects->pluck('id')->flip();
        $rows = [];

        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent): void {
            foreach ($byParent->get($parentId, collect()) as $project) {
                $rows[] = self::present($project, $depth);
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
                $rows[] = self::present($project, 0);
                $walk($project->id, 1);
            }
        }

        return $rows;
    }

    /**
     * Options for a project picker: active projects only, in tree order.
     *
     * @return array<int, array{id: int, code: string, name: string, depth: int}>
     */
    public static function options(): array
    {
        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'depth' => $row['depth'],
        ], self::tree(activeOnly: true));
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

    /** @return array<string, mixed> */
    private static function present(Project $project, int $depth): array
    {
        return [
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
            'expected_amount' => $project->expected_amount,
            'notes' => $project->notes,
        ];
    }
}
