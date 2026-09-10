<?php

namespace App\Domains\Accounting\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Raised when a shorthand journal line names a VAT code the API does not know.
 *
 * Deliberately not a validation rule: the API answers with the stable error
 * code `unknown_vat_code` so a client can tell a wrong code apart from a
 * malformed payload.
 */
class UnknownVatCodeException extends DomainException
{
    public static function forCode(string $code): self
    {
        return new self("Unknown VAT code '{$code}'.");
    }
}
