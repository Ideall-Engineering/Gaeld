<?php

namespace App\Domains\Projects\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * An allocation names a project or person outside the organization, or its
 * shares do not add up.
 */
class InvalidAllocationException extends DomainException
{
    public function __construct(string $message = 'Invalid allocation.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
