<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;

class InvoicePdfPreviewApiController extends Controller
{
    /**
     * Render a draft invoice as it will look, before it is finalised and booked.
     *
     * Kept apart from api.invoices.pdf so that endpoint keeps one meaning: the
     * document a customer may be sent and may pay. This one is stamped as a
     * draft, carries no QR payment part, and writes nothing — in particular no
     * payment reference, which cannot exist before the invoice number does.
     */
    public function __invoke(
        Invoice $invoice,
        GenerateQrInvoicePdfAction $action,
        CurrentOrganization $currentOrganization,
    ): HttpResponse|JsonResponse {
        $this->authorize('view', $invoice);

        if ($invoice->status !== InvoiceStatus::Draft) {
            return response()->json([
                'message' => __('app.invoice_preview_draft_only'),
                'code' => 'invoice_not_draft',
            ], 422);
        }

        $organization = $currentOrganization->get();
        $locale = $organization->locale ?? app()->getLocale();

        $pdf = $action->execute($invoice, $organization, $locale, preview: true);

        return new HttpResponse($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-draft-'.$invoice->id.'.pdf"',
        ]);
    }
}
