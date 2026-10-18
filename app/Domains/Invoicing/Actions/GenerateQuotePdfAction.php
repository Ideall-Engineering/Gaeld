<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Services\InvoicePdfRenderer;
use App\Domains\Invoicing\Support\InvoicePdfStyle;
use App\Domains\Organizations\Models\Organization;
use TCPDF;

/**
 * The quote as a PDF, in the invoice layout without a payment slip. A draft
 * carries the draft mark.
 */
class GenerateQuotePdfAction
{
    public function __construct(private InvoicePdfRenderer $pdfRenderer) {}

    public function execute(Quote $quote, Organization $organization, string $language = 'de'): string
    {
        $quote->loadMissing(['customer', 'lines.vatRate']);

        $tcpdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $tcpdf->setPrintHeader(false);
        $tcpdf->setPrintFooter(false);
        $tcpdf->SetMargins(InvoicePdfStyle::MARGIN_LEFT, InvoicePdfStyle::MARGIN_TOP, InvoicePdfStyle::MARGIN_RIGHT);
        $tcpdf->SetAutoPageBreak(true, 20);
        $tcpdf->AddPage();

        $this->pdfRenderer->setLocale($language);
        if ($quote->status === QuoteStatus::Draft) {
            $this->pdfRenderer->renderDraftWatermark($tcpdf);
        }
        $this->pdfRenderer->renderFoldMarks($tcpdf);
        $this->pdfRenderer->renderInvoiceHeader($tcpdf, $quote, $organization);
        $this->pdfRenderer->renderLineItems($tcpdf, $quote);
        $this->pdfRenderer->renderTotals($tcpdf, $quote, $organization);
        $this->pdfRenderer->renderFooter($tcpdf);

        return $tcpdf->Output('', 'S');
    }
}
