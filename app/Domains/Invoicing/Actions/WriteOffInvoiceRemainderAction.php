<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Exceptions\InvalidPaymentException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Settles the open rest of an invoice that will not be paid (cash discount,
 * rounding, bad debt) as a revenue correction.
 */
class WriteOffInvoiceRemainderAction
{
    public function __construct(
        private InvoiceAccountingService $accountingService,
    ) {}

    public function execute(Invoice $invoice, string $date, string $reason): InvoicePayment
    {
        if ($invoice->type !== InvoiceType::Invoice
            || ! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true)) {
            throw new InvalidInvoiceStateException(__('app.write_off_needs_open_invoice'));
        }

        $rest = $invoice->amountDue();
        if (! Money::isPositive($rest)) {
            throw new InvalidPaymentException(__('app.write_off_nothing_open'));
        }

        $payment = $this->accountingService->writeOffRemainder($invoice, $date, $reason);

        Log::info('Invoice rest written off', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'amount' => $rest,
            'organization_id' => $invoice->organization_id,
        ]);

        return $payment;
    }
}
