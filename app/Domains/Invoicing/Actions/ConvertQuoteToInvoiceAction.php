<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Exceptions\InvalidQuoteStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Models\QuoteLine;
use App\Domains\Invoicing\Services\InvoiceNumberGenerator;
use App\Domains\Projects\Services\InvoiceProjectService;
use App\Domains\Projects\Services\QuoteJobStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Turns an accepted (or sent) quote into an invoice draft with the same
 * customer, lines and totals. The quote is marked accepted and keeps a link
 * to the invoice; it can be converted only once. The invoice is a draft, so
 * nothing is booked until it is finalized as usual.
 */
class ConvertQuoteToInvoiceAction
{
    public function __construct(
        private CreateInvoiceAction $createInvoice,
        private InvoiceNumberGenerator $invoiceNumbers,
        private InvoiceProjectService $invoiceProjects,
        private QuoteJobStatusService $jobStatus,
    ) {}

    public function execute(Quote $quote, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($quote, $userId): Invoice {
            $quote = Quote::withoutGlobalScopes()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            if ($quote->invoice_id !== null) {
                throw new InvalidQuoteStateException(__('app.quote_already_invoiced'));
            }
            if (! in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Accepted], true)) {
                throw new InvalidQuoteStateException(__('app.quote_convert_needs_sent'));
            }
            if ($quote->customer_id === null) {
                throw new InvalidQuoteStateException(__('app.quote_customer_required'));
            }

            $quote->load(['lines', 'organization', 'customer']);
            $days = $quote->payment_terms ?? $quote->customer->payment_terms ?? $quote->organization->default_payment_terms_days;
            $issueDate = now();

            $invoice = $this->createInvoice->execute(new CreateInvoiceData(
                organizationId: $quote->organization_id,
                customerId: (string) $quote->customer_id,
                number: $this->invoiceNumbers->next($quote->organization_id),
                issueDate: $issueDate->toDateString(),
                dueDate: is_numeric($days) ? $issueDate->copy()->addDays((int) $days)->toDateString() : null,
                currency: $quote->currency,
                notes: $quote->notes,
                paymentTerms: $quote->payment_terms,
                lines: $quote->lines->map(fn (QuoteLine $line) => new InvoiceLineData(
                    description: (string) $line->description,
                    quantity: (string) $line->quantity,
                    unitPrice: (string) $line->unit_price,
                    type: $line->type,
                    discountType: $line->discount_type,
                    vatRateId: $line->vat_rate_id === null ? null : (string) $line->vat_rate_id,
                    sortOrder: $line->sort_order,
                ))->all(),
                taxTreatment: $quote->tax_treatment,
            ));

            if ($quote->cost_center_id !== null) {
                $this->invoiceProjects->assign($invoice, $quote->cost_center_id, $userId);
            }

            $quote->forceFill([
                'status' => QuoteStatus::Accepted,
                'decided_at' => $quote->decided_at ?? now(),
                'invoice_id' => $invoice->id,
            ])->save();
            $this->jobStatus->follow($quote);

            return $invoice;
        });
    }
}
