<?php

namespace App\Domains\Invoicing\Exceptions;

use App\Support\Exceptions\DomainException;

class InvalidQuoteStateException extends DomainException
{
    public function __construct(string $message = 'Quote is in an invalid state for this operation.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
