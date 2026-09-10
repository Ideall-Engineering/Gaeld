<?php

namespace App\Domains\Accounting\Exceptions;

use App\Support\Exceptions\DomainException;

class JournalEntryAlreadyReversedException extends DomainException
{
    public function __construct(string $message = 'This journal entry has already been reversed.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'journal_entry_already_reversed';
    }
}
