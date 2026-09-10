<?php

namespace App\Domains\Migration\DTOs;

class JournalEntryImportRow extends AbstractImportRow
{
    public function __construct(
        int $sourceRow,
        public readonly string $date,
        public readonly ?string $reference = null,
        public readonly ?string $description = null,
        /**
         * Either an explicit line (account_code plus debit and credit) or a
         * shorthand line carrying a VAT code, which the importer expands
         * against the chart of accounts.
         *
         * @var array<int, array<string, mixed>>
         */
        public readonly array $lines = [],
    ) {
        parent::__construct($sourceRow);
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'reference' => $this->reference,
            'description' => $this->description,
            'lines' => $this->lines,
        ];
    }
}
