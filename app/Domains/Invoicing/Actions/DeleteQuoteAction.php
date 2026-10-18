<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Exceptions\InvalidQuoteStateException;
use App\Domains\Invoicing\Models\Quote;

/**
 * Deletes a draft or declined quote. Its number is not given out again.
 */
class DeleteQuoteAction
{
    public function execute(Quote $quote): void
    {
        if (! $quote->status->isDeletable() || $quote->invoice_id !== null) {
            throw new InvalidQuoteStateException(__('app.quote_not_deletable'));
        }

        $quote->delete();
    }
}
