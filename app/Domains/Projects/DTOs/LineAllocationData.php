<?php

namespace App\Domains\Projects\DTOs;

use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Models\LineAllocation;

/**
 * One share of a journal line for a project and/or a person (spec 009).
 *
 * `share` is a percentage as a bcmath string, greater than 0 and at most 100.
 * `source` is left null by callers that type allocations in; whoever writes
 * them then records its own source.
 */
readonly class LineAllocationData
{
    public function __construct(
        public ?int $projectId,
        public ?string $employeeId,
        public string $share = '100',
        public ?AllocationSource $source = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $projectId = $data['project_id'] ?? $data['cost_center_id'] ?? null;

        return new self(
            projectId: $projectId !== null && $projectId !== '' ? (int) $projectId : null,
            employeeId: isset($data['employee_id']) && $data['employee_id'] !== '' ? (string) $data['employee_id'] : null,
            share: isset($data['share']) ? (string) $data['share'] : '100',
        );
    }

    public static function fromModel(LineAllocation $allocation): self
    {
        return new self(
            projectId: $allocation->cost_center_id,
            employeeId: $allocation->employee_id,
            share: (string) $allocation->share,
            source: AllocationSource::Carried,
        );
    }

    /**
     * Read the `allocations` of one submitted line, with `project_id` (or
     * `cost_center_id`) on the line as a shorthand for a whole-line share.
     * Returns null when the line says nothing about allocations at all.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, self>|null
     */
    public static function listFromLine(array $line): ?array
    {
        if (array_key_exists('allocations', $line)) {
            return array_values(array_map(
                fn (array $allocation) => self::fromArray($allocation),
                (array) ($line['allocations'] ?? []),
            ));
        }

        foreach (['project_id', 'cost_center_id'] as $shorthand) {
            if (array_key_exists($shorthand, $line)) {
                return filled($line[$shorthand])
                    ? [new self(projectId: (int) $line[$shorthand], employeeId: null)]
                    : [];
            }
        }

        return null;
    }

    /** @return array{project_id: int|null, employee_id: string|null, share: string} */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'employee_id' => $this->employeeId,
            'share' => $this->share,
        ];
    }
}
