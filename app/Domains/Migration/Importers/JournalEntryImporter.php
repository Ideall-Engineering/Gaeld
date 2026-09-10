<?php

namespace App\Domains\Migration\Importers;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Support\VatCodeResolver;
use App\Domains\Migration\Contracts\DataTypeImporterInterface;
use App\Domains\Migration\DTOs\ImportResult;
use App\Domains\Migration\DTOs\JournalEntryImportRow;
use App\Domains\Migration\DTOs\ValidationResult;
use App\Domains\Migration\Enums\DataType;
use App\Domains\Organizations\Models\Organization;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class JournalEntryImporter implements DataTypeImporterInterface
{
    public function __construct(
        private readonly VatCodeResolver $vatCodeResolver,
        private readonly LedgerService $ledgerService,
    ) {}

    public function dataType(): DataType
    {
        return DataType::JournalEntries;
    }

    public function dependencies(): array
    {
        return [DataType::Accounts];
    }

    public function validate(Collection $rows, Organization $organization): ValidationResult
    {
        $errors = [];
        $accountCodes = Account::where('organization_id', $organization->id)
            ->pluck('code')
            ->flip();

        foreach ($rows as $row) {
            if (! $row instanceof JournalEntryImportRow || ! $row->isValid()) {
                continue;
            }

            if (empty($row->lines)) {
                $errors[$row->sourceRow()] = ['Journal entry must have at least one line'];

                continue;
            }

            try {
                $lines = $this->explicitLines($row, $organization);
            } catch (DomainException $exception) {
                $errors[$row->sourceRow()][] = $exception->getMessage();

                continue;
            }

            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach ($lines as $line) {
                if (! $accountCodes->has($line['account_code'])) {
                    $errors[$row->sourceRow()][] = "Account {$line['account_code']} not found";
                }
                $totalDebit += (float) ($line['debit'] ?? 0);
                $totalCredit += (float) ($line['credit'] ?? 0);
            }

            if (abs($totalDebit - $totalCredit) > 0.01) {
                $errors[$row->sourceRow()][] = "Entry is not balanced: debits ({$totalDebit}) ≠ credits ({$totalCredit})";
            }
        }

        if (! empty($errors)) {
            return ValidationResult::failure([], $errors);
        }

        return ValidationResult::success();
    }

    public function import(Collection $rows, Organization $organization): ImportResult
    {
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        $accounts = Account::where('organization_id', $organization->id)
            ->pluck('id', 'code');

        foreach ($rows as $row) {
            if (! $row instanceof JournalEntryImportRow || ! $row->isValid()) {
                $skipped++;

                continue;
            }

            $rowNumber = $row->sourceRow();

            try {
                $lines = $this->explicitLines($row, $organization);
            } catch (DomainException $exception) {
                $failed++;
                $errors[] = "Row {$rowNumber}: {$exception->getMessage()}";

                continue;
            }

            $missing = array_values(array_filter(
                array_map(static fn (array $line): string => (string) $line['account_code'], $lines),
                static fn (string $code): bool => ! $accounts->has($code),
            ));

            if ($missing !== []) {
                // Reported, never silently dropped: a missing account is a gap
                // in the chart of accounts and the operator has to see it.
                $skipped++;
                $errors[] = "Row {$rowNumber}: account(s) ".implode(', ', array_unique($missing)).' not found';

                continue;
            }

            try {
                $this->ledgerService->postEntry($organization->id, new JournalEntryData(
                    date: $row->date,
                    reference: $row->reference,
                    description: $row->description,
                    lines: array_map(
                        fn (array $line): JournalLineData => new JournalLineData(
                            accountId: (string) $accounts->get($line['account_code']),
                            debit: (string) ($line['debit'] ?? '0'),
                            credit: (string) ($line['credit'] ?? '0'),
                            description: $line['description'] ?? null,
                            vatRateId: $line['vat_rate_id'] ?? null,
                            vatAmount: isset($line['vat_amount']) ? (string) $line['vat_amount'] : null,
                            vatType: $line['vat_type'] ?? null,
                            vatFigure: $line['vat_figure'] ?? null,
                        ),
                        $lines,
                    ),
                    type: 'migration',
                ));

                $imported++;
            } catch (\Throwable $exception) {
                $failed++;
                $errors[] = "Row {$rowNumber}: {$exception->getMessage()}";
                Log::warning('Migration import: journal entry row failed', [
                    'row' => $rowNumber,
                    'error' => $exception->getMessage(),
                    'organization_id' => $organization->id,
                ]);
            }
        }

        if ($imported === 0 && $failed > 0) {
            return ImportResult::failure($this->dataType(), $errors, failed: $failed);
        }

        return ImportResult::success($this->dataType(), $imported, $skipped, warnings: $errors, failed: $failed);
    }

    /**
     * A line carrying a `vat_code` is shorthand and gets expanded against the
     * chart of accounts; every other line is already explicit and passes
     * through untouched, so parsers without VAT support are unaffected.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws DomainException
     */
    private function explicitLines(JournalEntryImportRow $row, Organization $organization): array
    {
        $lines = [];

        foreach ($row->lines as $line) {
            if (! isset($line['vat_code'])) {
                $lines[] = $line;

                continue;
            }

            foreach ($this->vatCodeResolver->expand($organization->id, $line) as $expanded) {
                $lines[] = $expanded;
            }
        }

        return $lines;
    }
}
