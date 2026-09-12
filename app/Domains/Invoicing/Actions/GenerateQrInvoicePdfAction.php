<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Exceptions\QrBillValidationException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoicePdfRenderer;
use App\Domains\Invoicing\Services\SwissQrInvoiceService;
use App\Domains\Invoicing\Support\InvoicePdfStyle;
use App\Domains\Organizations\Models\Organization;
use Sprain\SwissQrBill\PaymentPart\Output\DisplayOptions;
use Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput;
use TCPDF;

class GenerateQrInvoicePdfAction
{
    public function __construct(
        private SwissQrInvoiceService $qrService,
        private InvoicePdfRenderer $pdfRenderer,
    ) {}

    /**
     * Generate a PDF invoice with Swiss QR payment slip.
     *
     * With $preview the document is marked as a draft and the QR payment part
     * is left out. That is not a cosmetic difference: the payment reference is
     * derived from the invoice number, which a draft does not have yet, so
     * building a QR bill here would both invent a reference and — because
     * ensureQrReference() keeps whatever it finds — make the finalised invoice
     * carry one that encodes no number. A preview writes nothing.
     *
     * Returns raw PDF binary string.
     */
    public function execute(Invoice $invoice, Organization $organization, string $language = 'en', bool $preview = false): string
    {
        $invoice->loadMissing(['customer', 'lines.vatRate']);

        $tcpdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $tcpdf->setPrintHeader(false);
        $tcpdf->setPrintFooter(false);
        $tcpdf->SetMargins(InvoicePdfStyle::MARGIN_LEFT, InvoicePdfStyle::MARGIN_TOP, InvoicePdfStyle::MARGIN_RIGHT);
        $tcpdf->SetAutoPageBreak(false);
        $tcpdf->AddPage();
        $tcpdf->SetFillColor(255, 255, 255);
        $tcpdf->Rect(0, 0, $tcpdf->getPageWidth(), $tcpdf->getPageHeight(), 'F');

        // --- INVOICE CONTENT ---
        $this->pdfRenderer->setLocale($language);

        // Behind the content, so the text stays readable over it.
        if ($preview) {
            $this->pdfRenderer->renderDraftWatermark($tcpdf);
        }

        $this->pdfRenderer->renderFoldMarks($tcpdf);
        $this->pdfRenderer->renderInvoiceHeader($tcpdf, $invoice, $organization);
        $this->pdfRenderer->renderLineItems($tcpdf, $invoice);
        $this->pdfRenderer->renderTotals($tcpdf, $invoice, $organization);
        $this->pdfRenderer->renderFooter($tcpdf);

        if ($preview) {
            $this->pdfRenderer->renderDraftPaymentNote($tcpdf);

            return $tcpdf->Output('', 'S');
        }

        // --- QR PAYMENT SLIP (bottom of page) ---
        $violations = $this->qrService->validate($invoice, $organization);
        if ($violations !== []) {
            throw new QrBillValidationException($violations);
        }

        $qrBill = $this->qrService->buildQrBill($invoice, $organization);
        $langMap = ['en' => 'en', 'de' => 'de', 'fr' => 'fr', 'it' => 'it', 'rm' => 'de'];
        $qrLang = $langMap[$language] ?? 'en';

        // Place the QR payment slip + receipt on a dedicated second page.
        $tcpdf->AddPage();
        $tcpdf->SetFillColor(255, 255, 255);
        $tcpdf->Rect(0, 0, $tcpdf->getPageWidth(), $tcpdf->getPageHeight(), 'F');

        $output = new TcPdfOutput($qrBill, $qrLang, $tcpdf);
        $displayOptions = (new DisplayOptions)->setPrintable(false);
        $output->setDisplayOptions($displayOptions)->getPaymentPart();

        return $tcpdf->Output('', 'S');
    }
}
