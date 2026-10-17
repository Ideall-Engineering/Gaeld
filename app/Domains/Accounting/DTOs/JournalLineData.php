<?php

namespace App\Domains\Accounting\DTOs;

use App\Domains\Projects\DTOs\LineAllocationData;
use App\Support\ValidatesFromArray;

/**
 * Value object representing a single debit/credit line in a journal entry.
 *
 * Amounts are always strings (bcmath-compatible). Callers must pass
 * string values; use '0' instead of integer 0.
 *
 * `allocations` (spec 009) is null when the caller says nothing about
 * projects and people, and an empty array when it clears them.
 */
readonly class JournalLineData
{
    use ValidatesFromArray;

    public function __construct(
        public string $accountId,
        public string $debit,
        public string $credit,
        public ?string $description = null,
        public ?string $vatRateId = null,
        public ?string $vatAmount = null,
        public ?string $vatType = null,
        public ?string $vatFigure = null,
        /** @var array<int, LineAllocationData>|null */
        public ?array $allocations = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        self::assertRequired($data, ['account_id', 'debit', 'credit']);

        return new self(
            accountId: $data['account_id'],
            debit: (string) $data['debit'],
            credit: (string) $data['credit'],
            description: $data['description'] ?? null,
            vatRateId: $data['vat_rate_id'] ?? null,
            vatAmount: isset($data['vat_amount']) ? (string) $data['vat_amount'] : null,
            vatType: $data['vat_type'] ?? null,
            vatFigure: $data['vat_figure'] ?? null,
            allocations: LineAllocationData::listFromLine($data),
        );
    }

    /**
     * The same line with other allocations; everything else stays as is.
     *
     * @param  array<int, LineAllocationData>|null  $allocations
     */
    public function withAllocations(?array $allocations): self
    {
        return new self(
            accountId: $this->accountId,
            debit: $this->debit,
            credit: $this->credit,
            description: $this->description,
            vatRateId: $this->vatRateId,
            vatAmount: $this->vatAmount,
            vatType: $this->vatType,
            vatFigure: $this->vatFigure,
            allocations: $allocations,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
            'vat_rate_id' => $this->vatRateId,
            'vat_amount' => $this->vatAmount,
            'vat_type' => $this->vatType,
            'vat_figure' => $this->vatFigure,
            'allocations' => $this->allocations === null
                ? null
                : array_map(fn (LineAllocationData $allocation) => $allocation->toArray(), $this->allocations),
        ];
    }
}
