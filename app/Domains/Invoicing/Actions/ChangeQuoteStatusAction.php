<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Exceptions\InvalidQuoteStateException;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Projects\Services\QuoteJobStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Moves a quote along its lifecycle: sent, accepted, declined, or back.
 * The quote's job follows (see QuoteJobStatusService).
 */
class ChangeQuoteStatusAction
{
    public function __construct(private QuoteJobStatusService $jobStatus) {}

    public function execute(Quote $quote, QuoteStatus $target): Quote
    {
        if ($quote->status === $target) {
            return $quote;
        }

        if (! $quote->status->canTransitionTo($target)) {
            throw new InvalidQuoteStateException(__('app.quote_transition_invalid', [
                'from' => $quote->status->label(),
                'to' => $target->label(),
            ]));
        }

        // An accepted quote already turned into an invoice stays accepted.
        if ($quote->invoice_id !== null) {
            throw new InvalidQuoteStateException(__('app.quote_already_invoiced'));
        }

        if ($target !== QuoteStatus::Draft && $quote->customer_id === null) {
            throw new InvalidQuoteStateException(__('app.quote_customer_required'));
        }

        $quote->status = $target;
        if ($target === QuoteStatus::Sent) {
            $quote->sent_at ??= now();
        }
        $quote->decided_at = in_array($target, [QuoteStatus::Accepted, QuoteStatus::Declined], true) ? now() : null;

        DB::transaction(function () use ($quote): void {
            $quote->save();
            $this->jobStatus->follow($quote);
        });

        return $quote;
    }
}
