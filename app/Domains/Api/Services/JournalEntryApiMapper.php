<?php

namespace App\Domains\Api\Services;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Support\VatCodeResolver;

final class JournalEntryApiMapper
{
    public function __construct(
        private AccountCodeResolver $accountResolver,
        private VatCodeResolver $vatCodeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function toData(array $validated, string $organizationId): JournalEntryData
    {
        $lines = [];

        foreach ($validated['lines'] as $line) {
            foreach ($this->explicitLines($organizationId, $line) as $explicit) {
                $lines[] = new JournalLineData(
                    accountId: (string) $this->accountResolver
                        ->resolve($organizationId, (string) $explicit['account_code'])
                        ->id,
                    debit: (string) $explicit['debit'],
                    credit: (string) $explicit['credit'],
                    description: $explicit['description'] ?? null,
                    vatRateId: $explicit['vat_rate_id'] ?? null,
                    vatAmount: isset($explicit['vat_amount']) ? (string) $explicit['vat_amount'] : null,
                    vatType: $explicit['vat_type'] ?? null,
                    vatFigure: $explicit['vat_figure'] ?? null,
                );
            }
        }

        return new JournalEntryData(
            date: $validated['date'],
            reference: $validated['reference'] ?? null,
            description: $validated['description'] ?? null,
            lines: $lines,
            type: 'api',
        );
    }

    /**
     * A shorthand line becomes several explicit ones; an explicit line stays
     * as it is.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, array<string, mixed>>
     */
    private function explicitLines(string $organizationId, array $line): array
    {
        if (! isset($line['vat_code'])) {
            return [$line];
        }

        return $this->vatCodeResolver->expand($organizationId, $line);
    }
}
