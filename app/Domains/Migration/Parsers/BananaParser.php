<?php

namespace App\Domains\Migration\Parsers;

use App\Domains\Migration\Contracts\ImportRowInterface;
use App\Domains\Migration\Contracts\PlatformParserInterface;
use App\Domains\Migration\DTOs\AccountImportRow;
use App\Domains\Migration\DTOs\JournalEntryImportRow;
use App\Domains\Migration\DTOs\OpeningBalanceRow;
use App\Domains\Migration\Enums\DataType;
use App\Domains\Migration\Enums\Platform;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Parses Banana Comptabilité TXT/CSV export files.
 *
 * Banana exports accounts + journal (Écritures) as tab-separated or
 * semicolon-separated files.
 */
class BananaParser implements PlatformParserInterface
{
    use CsvRowLookup;

    public function platform(): Platform
    {
        return Platform::Banana;
    }

    public function labelKey(): string
    {
        return 'migration.platform_banana';
    }

    public function descriptionKey(): string
    {
        return 'migration.platform_banana_desc';
    }

    public function supportedDataTypes(): array
    {
        return [
            DataType::Accounts,
            DataType::JournalEntries,
            DataType::OpeningBalances,
        ];
    }

    public function acceptedExtensions(): array
    {
        return ['csv', 'txt', 'tsv'];
    }

    public function parse(UploadedFile $file, DataType $dataType): Collection
    {
        $content = $file->get();
        $delimiter = $this->detectDelimiter($content);
        $rows = $this->parseCsv($content, $delimiter);

        if (empty($rows)) {
            return collect();
        }

        $mapped = collect($rows)
            ->map(fn (array $row, int $index) => $this->mapRow($row, $index + 1, $dataType))
            ->filter();

        return $dataType === DataType::JournalEntries
            ? $this->groupByDocument($mapped)
            : $mapped;
    }

    /**
     * Banana writes one CSV row per debit/credit pair. Rows sharing a document
     * number on the same date are one booking — a payroll run arrives as
     * fifteen rows under a single number. They must become a single journal
     * entry, because the reference is unique per organization.
     *
     * @param  Collection<int, ImportRowInterface>  $rows
     * @return Collection<int, ImportRowInterface>
     */
    private function groupByDocument(Collection $rows): Collection
    {
        /** @var array<string, JournalEntryImportRow> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            if (! $row instanceof JournalEntryImportRow) {
                continue;
            }

            $key = $row->date.'|'.($row->reference ?? '');

            if (! isset($grouped[$key])) {
                $grouped[$key] = $row;

                continue;
            }

            $existing = $grouped[$key];
            $merged = new JournalEntryImportRow(
                sourceRow: $existing->sourceRow(),
                date: $existing->date,
                reference: $existing->reference,
                description: $existing->description,
                lines: [...$existing->lines, ...$row->lines],
            );

            foreach ([...$existing->warnings(), ...$row->warnings()] as $warning) {
                $merged->addWarning($warning);
            }

            if (! $existing->isValid() || ! $row->isValid()) {
                $merged->markInvalid();
            }

            $grouped[$key] = $merged;
        }

        /** @var Collection<int, ImportRowInterface> $result */
        $result = collect(array_values($grouped));

        return $result;
    }

    public function detectDataType(UploadedFile $file): ?DataType
    {
        $content = $file->get();
        $delimiter = $this->detectDelimiter($content);
        $rows = $this->parseCsv($content, $delimiter);

        if (empty($rows)) {
            return null;
        }

        $headers = array_keys($rows[0]);
        $headerString = implode(',', array_map('strtolower', $headers));

        if (str_contains($headerString, 'group') || str_contains($headerString, 'bclass')) {
            return DataType::Accounts;
        }
        if (str_contains($headerString, 'doc') && str_contains($headerString, 'accountdebit')) {
            return DataType::JournalEntries;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapRow(array $row, int $sourceRow, DataType $dataType): ?ImportRowInterface
    {
        return match ($dataType) {
            DataType::Accounts => $this->mapAccount($row, $sourceRow),
            DataType::JournalEntries => $this->mapJournalEntry($row, $sourceRow),
            DataType::OpeningBalances => $this->mapOpeningBalance($row, $sourceRow),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapAccount(array $row, int $sourceRow): AccountImportRow
    {
        $code = $this->findValue($row, ['account', 'konto', 'compte', 'conto']);
        $name = $this->findValue($row, ['description', 'beschreibung', 'libellé', 'descrizione']);
        $bclass = $this->findValue($row, ['bclass', 'classe']);

        $type = match ($bclass) {
            '1' => 'asset',
            '2' => 'liability',
            '3' => 'expense',
            '4' => 'revenue',
            default => 'asset',
        };

        $importRow = new AccountImportRow(
            sourceRow: $sourceRow,
            code: $code ?? '',
            name: $name ?? '',
            type: $type,
        );

        if (! $code || ! $name) {
            $importRow->markInvalid();
            $importRow->addWarning('Missing required field: account or description');
        }

        return $importRow;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapJournalEntry(array $row, int $sourceRow): JournalEntryImportRow
    {
        $date = $this->normalizeDate($this->findValue($row, ['date', 'datum', 'data']));
        $debitAccount = $this->findValue($row, ['accountdebit', 'kontosoll', 'comptedébit', 'contodare']);
        $creditAccount = $this->findValue($row, ['accountcredit', 'kontohaben', 'comptecrédit', 'contoavere']);
        $amount = $this->normalizeAmount($this->findValue($row, ['amount', 'betrag', 'montant', 'importo']));
        $description = $this->findValue($row, ['description', 'beschreibung', 'libellé', 'descrizione']);
        $doc = $this->findValue($row, ['doc', 'beleg', 'pièce', 'documento']);
        $vatCode = $this->findValue($row, ['vatcode', 'mwst-code', 'mwstcode', 'codetva', 'codiceiva']);
        $vatFigure = $this->findValue($row, ['vatfigure', 'mwst-ziffer', 'ziffer']);

        $lines = $this->buildLines($debitAccount, $creditAccount, $amount, $description, $vatCode, $vatFigure);

        $importRow = new JournalEntryImportRow(
            sourceRow: $sourceRow,
            date: $date ?? '',
            reference: $this->buildReference($date, $doc),
            description: $description,
            lines: $lines,
        );

        if (! $date || empty($lines)) {
            $importRow->markInvalid();
            $importRow->addWarning('Missing required field: date, amount, debit account, or credit account');
        }

        return $importRow;
    }

    /**
     * A row with a VAT code becomes a single shorthand line that the importer
     * expands against the chart of accounts; a row without one becomes the
     * plain debit/credit pair it already is.
     *
     * Which side carries the VAT follows the code: V codes are turnover, so the
     * credit account is the subject; M, I and B codes are expenses, so the
     * debit account is.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(
        ?string $debitAccount,
        ?string $creditAccount,
        ?string $amount,
        ?string $description,
        ?string $vatCode,
        ?string $vatFigure,
    ): array {
        if (! $debitAccount || ! $creditAccount || $amount === null || $amount === '') {
            return [];
        }

        if ($vatCode === null || $vatCode === '') {
            return [
                ['account_code' => $debitAccount, 'debit' => $amount, 'credit' => null, 'description' => $description],
                ['account_code' => $creditAccount, 'debit' => null, 'credit' => $amount, 'description' => $description],
            ];
        }

        $isTurnover = str_starts_with(strtoupper($vatCode), 'V');

        return [[
            'account_code' => $isTurnover ? $creditAccount : $debitAccount,
            'contra_account_code' => $isTurnover ? $debitAccount : $creditAccount,
            'gross' => $amount,
            'vat_code' => strtoupper($vatCode),
            'vat_figure' => $vatFigure,
            'description' => $description,
        ]];
    }

    /**
     * Banana document numbers repeat across months — 1090 appears in May and in
     * June. The date makes the reference unique, which the unique index on
     * (organization_id, reference) requires.
     */
    private function buildReference(?string $date, ?string $doc): ?string
    {
        if ($doc === null || $doc === '' || $date === null) {
            return null;
        }

        return 'BAN-'.str_replace('-', '', $date).'-'.$doc;
    }

    /** Banana exports dates as DD.MM.YY or DD.MM.YYYY. */
    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $value, $matches) !== 1) {
            return null;
        }

        [, $day, $month, $year] = $matches;
        if (strlen($year) === 2) {
            $year = '20'.$year;
        }

        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    /** Banana writes thousands with an apostrophe: 3'675.40. */
    private function normalizeAmount(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(["'", ' ', "\u{00a0}"], '', $value);

        return is_numeric($normalized) ? number_format((float) $normalized, 2, '.', '') : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapOpeningBalance(array $row, int $sourceRow): OpeningBalanceRow
    {
        $code = $this->findValue($row, ['account', 'konto', 'compte', 'conto']);
        $name = $this->findValue($row, ['description', 'beschreibung', 'libellé', 'descrizione']);
        $opening = $this->findValue($row, ['opening', 'eröffnung', 'ouverture', 'apertura']);

        $debit = null;
        $credit = null;
        if ($opening !== null) {
            $amount = (float) str_replace(["'", ' '], '', $opening);
            if ($amount > 0) {
                $debit = (string) $amount;
            } elseif ($amount < 0) {
                $credit = (string) abs($amount);
            }
        }

        $importRow = new OpeningBalanceRow(
            sourceRow: $sourceRow,
            accountCode: $code ?? '',
            accountName: $name,
            debit: $debit,
            credit: $credit,
        );

        if (! $code) {
            $importRow->markInvalid();
            $importRow->addWarning('Missing required field: account code');
        }

        return $importRow;
    }

    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n");

        if ($firstLine === false) {
            return ';';
        }

        $tabCount = substr_count($firstLine, "\t");
        $semicolonCount = substr_count($firstLine, ';');
        $commaCount = substr_count($firstLine, ',');

        if ($tabCount >= $semicolonCount && $tabCount >= $commaCount) {
            return "\t";
        }
        if ($semicolonCount >= $commaCount) {
            return ';';
        }

        return ',';
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function parseCsv(string $content, string $delimiter): ?array
    {
        $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $content)));

        if (count($lines) < 2) {
            return null;
        }

        $headers = str_getcsv(array_shift($lines), $delimiter);
        $headers = array_map('trim', $headers);

        $rows = [];
        foreach ($lines as $line) {
            // Only the line break may go. trim() would also eat trailing tabs,
            // and with them the empty columns at the end of a row — a booking
            // without a VAT amount would arrive one field short and be dropped.
            $line = rtrim($line, "\r\n");
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter);
            if (count($values) !== count($headers)) {
                continue;
            }

            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    }
}
