<?php

namespace Tests\Traits;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;

/**
 * Fixtures for projects and line allocations (spec 009).
 *
 * Expects `$this->organization` to be set (see WithAuthenticatedOrganization).
 */
trait BuildsProjects
{
    protected function enableProjects(): void
    {
        $modules = $this->organization->enabled_modules ?? [];
        $modules['projects'] = true;
        $this->organization->update(['enabled_modules' => $modules]);
    }

    protected function makeProject(string $code, ?Project $parent = null, ProjectKind $kind = ProjectKind::Project, bool $active = true): Project
    {
        return Project::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => "Project {$code}",
            'parent_id' => $parent?->id,
            'kind' => $kind,
            'is_active' => $active,
        ]);
    }

    /** @return array<int, LineAllocationData> */
    protected function whole(Project $project): array
    {
        return [new LineAllocationData(projectId: $project->id, employeeId: null)];
    }

    protected function lineOn(JournalEntry $entry, int $accountId): TransactionLine
    {
        return TransactionLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $accountId)
            ->orderBy('id')
            ->firstOrFail();
    }

    /** @return array<int, array{project: int|null, employee: string|null, share: string}> */
    protected function allocationsOf(TransactionLine $line): array
    {
        return LineAllocation::withoutGlobalScopes()
            ->where('transaction_line_id', $line->id)
            ->orderBy('id')
            ->get()
            ->map(fn (LineAllocation $a) => [
                'project' => $a->cost_center_id,
                'employee' => $a->employee_id,
                'share' => (string) $a->share,
            ])
            ->all();
    }
}
