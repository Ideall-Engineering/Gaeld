<?php

namespace App\Domains\Accounting\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Thrown when a correction is attempted on a journal entry that is owned by
 * another module (invoice, expense, bank reconciliation, payroll, asset,
 * VAT settlement, or year-end closing). Such entries must be corrected
 * through their owning module's action instead.
 */
class SourceManagedEntryException extends DomainException
{
    public function __construct(string $message = 'This journal entry is managed by another module and cannot be corrected here.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'source_managed_entry';
    }
}
