<?php

namespace App\Domains\Projects\Support;

use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Models\Project;
use Illuminate\Validation\Rule;

/**
 * Allocations as the API writes them (spec 009): a project by its code, a
 * person by the employee UUID, a share in percent.
 */
final class ApiAllocations
{
    /**
     * @param  array<int, int>  $keptProjectIds  projects the entry already uses
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $organizationId, string $prefix, array $keptProjectIds = []): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array', 'max:20'],
            "{$prefix}.*.project_code" => [
                'nullable',
                'string',
                'max:30',
                'required_without:'."{$prefix}.*.employee_id",
                Rule::exists('cost_centers', 'code')
                    ->where('organization_id', $organizationId)
                    ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $keptProjectIds ?: [0])),
            ],
            "{$prefix}.*.employee_id" => [
                'nullable',
                'uuid',
                Rule::exists('employees', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
            "{$prefix}.*.share" => ['sometimes', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<int, LineAllocationData>
     */
    public static function toData(array $allocations): array
    {
        $codes = array_values(array_unique(array_filter(array_column($allocations, 'project_code'))));
        $ids = $codes === [] ? [] : Project::query()->whereIn('code', $codes)->pluck('id', 'code')->all();

        return array_values(array_map(fn (array $allocation) => new LineAllocationData(
            projectId: isset($allocation['project_code']) ? (int) $ids[$allocation['project_code']] : null,
            employeeId: $allocation['employee_id'] ?? null,
            share: isset($allocation['share']) ? (string) $allocation['share'] : '100',
            source: AllocationSource::Api,
        ), $allocations));
    }
}
