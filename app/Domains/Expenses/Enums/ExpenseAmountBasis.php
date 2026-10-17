<?php

namespace App\Domains\Expenses\Enums;

enum ExpenseAmountBasis: string
{
    case Gross = 'gross';
    case Net = 'net';

    /**
     * The basis the entered amount is actually on.
     *
     * Only a supplier who charged Swiss VAT puts it into the amount paid. For
     * acquisition tax, import VAT or no VAT at all the amount paid is already
     * net, so reading it as gross would carve a VAT share out of it that the
     * supplier never charged.
     */
    public static function effectiveFor(self $basis, ExpenseTaxTreatment $treatment): self
    {
        return $treatment->increasesPayableAmount() ? $basis : self::Net;
    }
}
