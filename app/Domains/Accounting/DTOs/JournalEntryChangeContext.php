<?php

namespace App\Domains\Accounting\DTOs;

use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Models\JournalEntryRevision;

/**
 * Who changes a posted journal entry, from where, and why.
 *
 * Recorded verbatim on the {@see JournalEntryRevision}
 * the change leaves behind.
 */
readonly class JournalEntryChangeContext
{
    public function __construct(
        public int $expectedRevision,
        public JournalCorrectionSource $source,
        public ?int $userId = null,
        public ?string $reason = null,
        public ?int $tokenId = null,
        public ?string $idempotencyKey = null,
        public bool $acknowledgeBankAmountChange = false,
    ) {}
}
