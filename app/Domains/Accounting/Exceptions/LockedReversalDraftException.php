<?php

namespace App\Domains\Accounting\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Thrown when a caller attempts to edit, post, or delete a correction's
 * reversal draft directly instead of through the correction as a whole.
 */
class LockedReversalDraftException extends DomainException
{
    public function __construct(string $message = 'The reversal draft of a correction cannot be changed directly.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'journal_correction_state_conflict';
    }
}
