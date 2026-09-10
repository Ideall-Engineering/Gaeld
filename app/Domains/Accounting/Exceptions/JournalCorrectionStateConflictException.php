<?php

namespace App\Domains\Accounting\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Thrown when an operation is attempted against a correction that is not in
 * the required status (e.g. posting or cancelling a correction that is
 * already posted).
 */
class JournalCorrectionStateConflictException extends DomainException
{
    public function __construct(string $message = 'This correction is not in a state that allows this operation.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'journal_correction_state_conflict';
    }
}
