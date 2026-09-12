<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\QrBillValidationException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Support\QrBillValidationMessageFormatter;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;

class InvoicePdfApiController extends Controller
{
    /**
     * Download an invoice PDF with its Swiss QR payment slip.
     */
    public function __invoke(
        Invoice $invoice,
        GenerateQrInvoicePdfAction $action,
        CurrentOrganization $currentOrganization,
        QrBillValidationMessageFormatter $messageFormatter,
    ): HttpResponse|JsonResponse {
        $this->authorize('view', $invoice);

        // This endpoint means "the document that may be sent and paid". A draft
        // has neither a number nor a payment reference; asking for its PDF here
        // used to build a QR bill and persist a reference of twenty zeros that
        // the finalisation then kept. api.invoices.pdf.preview serves drafts.
        if ($invoice->status === InvoiceStatus::Draft) {
            return response()->json([
                'message' => __('app.invoice_pdf_needs_finalising'),
                'code' => 'invoice_not_finalised',
            ], 422);
        }

        $organization = $currentOrganization->get();
        $bankAccount = $organization->defaultInvoicingBankAccount();
        $paymentIban = $invoice->qr_iban ?: $bankAccount?->qr_iban ?: $bankAccount?->iban;

        if (empty($paymentIban)) {
            return response()->json([
                'message' => __('app.qr_iban_required'),
                'code' => 'qr_iban_required',
            ], 422);
        }

        $locale = $organization->locale ?? app()->getLocale();

        try {
            $pdf = $action->execute($invoice, $organization, $locale);
        } catch (QrBillValidationException $exception) {
            return response()->json([
                'message' => $messageFormatter->format($exception->violations),
                'code' => 'qr_bill_invalid',
            ], 422);
        }

        $filename = 'invoice-'.Str::slug((string) ($invoice->number ?? $invoice->id)).'.pdf';

        return new HttpResponse($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
