<?php

namespace App\Domains\Accounting\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Thrown when an amendment would change what a bank-linked account holds
 * without the caller confirming that the ledger may then disagree with the
 * bank statement.
 */
class BankAmountChangeUnacknowledgedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('app.journal_entry_bank_amount_change_unacknowledged'));
    }

    public function errorCode(): string
    {
        return 'bank_amount_change_unacknowledged';
    }
}
