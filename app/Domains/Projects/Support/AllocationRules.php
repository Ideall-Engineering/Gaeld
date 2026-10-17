<?php

namespace App\Domains\Projects\Support;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Models\LineAllocation;
use Illuminate\Validation\Rule;

/**
 * Validation for allocations sent with journal lines (spec 009).
 *
 * A new allocation may only point at an active project. One that the entry
 * already had may keep pointing at a project closed since, so that saving an
 * entry never fails over an old allocation.
 */
final class AllocationRules
{
    /**
     * @param  array<int, int>  $keptProjectIds  projects the entry already uses
     * @param  array<int, string>  $keptEmployeeIds  people the entry already uses, also if they left since
     * @return array<string, array<int, mixed>>
     */
    public static function for(string $organizationId, string $prefix = 'lines.*.allocations', array $keptProjectIds = [], array $keptEmployeeIds = []): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array', 'max:20'],
            "{$prefix}.*.project_id" => [
                'nullable',
                'integer',
                Rule::exists('cost_centers', 'id')
                    ->where('organization_id', $organizationId)
                    ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $keptProjectIds ?: [0])),
            ],
            "{$prefix}.*.employee_id" => [
                'nullable',
                'uuid',
                Rule::exists('employees', 'id')
                    ->where('organization_id', $organizationId)
                    ->where(fn ($query) => $query->whereNull('deleted_at')->orWhereIn('id', $keptEmployeeIds ?: ['00000000-0000-0000-0000-000000000000'])),
            ],
            "{$prefix}.*.share" => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    /**
     * Projects the lines of an entry are allocated to today.
     *
     * @return array<int, int>
     */
    public static function projectsOf(?JournalEntry $entry): array
    {
        if ($entry === null) {
            return [];
        }

        return LineAllocation::withoutGlobalScopes()
            ->join('transaction_lines', 'transaction_lines.id', '=', 'line_allocations.transaction_line_id')
            ->where('transaction_lines.journal_entry_id', $entry->id)
            ->whereNotNull('line_allocations.cost_center_id')
            ->distinct()
            ->pluck('line_allocations.cost_center_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * People the lines of an entry are allocated to today.
     *
     * @return array<int, string>
     */
    public static function employeesOf(?JournalEntry $entry): array
    {
        if ($entry === null) {
            return [];
        }

        return LineAllocation::withoutGlobalScopes()
            ->join('transaction_lines', 'transaction_lines.id', '=', 'line_allocations.transaction_line_id')
            ->where('transaction_lines.journal_entry_id', $entry->id)
            ->whereNotNull('line_allocations.employee_id')
            ->distinct()
            ->pluck('line_allocations.employee_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Allocations of one validated line, or null when the line says nothing
     * or the caller may not set them.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, LineAllocationData>|null
     */
    public static function fromValidatedLine(array $line, bool $allowed): ?array
    {
        if (! $allowed || ! array_key_exists('allocations', $line)) {
            return null;
        }

        return array_values(array_map(
            fn (array $allocation) => LineAllocationData::fromArray($allocation),
            (array) ($line['allocations'] ?? []),
        ));
    }
}
