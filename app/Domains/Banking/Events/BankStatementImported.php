<?php

namespace App\Domains\Banking\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A bank statement finished importing and its transactions are committed.
 *
 * Dispatched after the import transaction commits, never inside it, so that
 * anything reacting to it sees the rows and cannot roll the import back.
 *
 * The import id doubles as the deduplication key for everything downstream:
 * handling the same import twice must stay a no-op.
 */
class BankStatementImported
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $bankImportId,
        public readonly string $organizationId,
        public readonly int $transactionCount,
    ) {}
}
