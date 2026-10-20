<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Models\QuoteLine;
use App\Domains\Invoicing\Support\InvoicePdfStyle;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use TCPDF;

/**
 * Renders invoice and quote content into a TCPDF document (spec 011):
 *
 * - sender top right: the organisation's header text when set, otherwise
 *   name, address and VAT number
 * - recipient in a left address window, with the contact person
 * - number, date, due date or validity and delivery note beside it
 * - title, intro, the lines (description, VAT, quantity, price per unit,
 *   total) and the totals per VAT rate
 * - on an invoice the bank details, payment terms and footer text
 *
 * Amounts use the Swiss format (1'234.50). All labels are localized via the
 * organization's locale.
 */
class InvoicePdfRenderer
{
    private string $locale = 'en';

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    private function t(string $key): string
    {
        return trans('app.'.$key, [], $this->locale);
    }

    /**
     * Draw the Swiss letter standard (SN 010130 / DIN 5008) fold and punch
     * marks on the left edge of the page so the sheet can be folded in three
     * for a C5/C6 window envelope, with the recipient address showing through
     * the window.
     */
    public function renderFoldMarks(TCPDF $tcpdf): void
    {
        $tcpdf->SetDrawColor(...InvoicePdfStyle::COLOR_GRAY);
        $tcpdf->SetLineWidth(0.1);

        foreach ([InvoicePdfStyle::FOLD_MARK_TOP_Y, InvoicePdfStyle::PUNCH_MARK_Y, InvoicePdfStyle::FOLD_MARK_BOTTOM_Y] as $y) {
            $tcpdf->Line(InvoicePdfStyle::FOLD_MARK_X, $y, InvoicePdfStyle::FOLD_MARK_X + InvoicePdfStyle::FOLD_MARK_LENGTH, $y);
        }

        $tcpdf->SetDrawColor(0, 0, 0);
        $tcpdf->SetLineWidth(0.2);
    }

    /**
     * Sender, recipient and document data: the top of the first page, and of
     * the page carrying the QR payment part.
     */
    public function renderLetterhead(TCPDF $tcpdf, Invoice|Quote $invoice, Organization $organization): void
    {
        // Wrapped and separate lines get the same spacing.
        $tcpdf->setCellHeightRatio(1.45);
        $this->renderLogo($tcpdf, $organization);
        $this->renderSender($tcpdf, $organization);
        $this->renderRecipient($tcpdf, $invoice);
        $this->renderMeta($tcpdf, $invoice);
    }

    public function renderInvoiceHeader(TCPDF $tcpdf, Invoice|Quote $invoice, Organization $organization): void
    {
        $this->renderLetterhead($tcpdf, $invoice, $organization);

        $tcpdf->SetXY(InvoicePdfStyle::MARGIN_LEFT, InvoicePdfStyle::INVOICE_TITLE_Y);
        $tcpdf->SetTextColor(...InvoicePdfStyle::COLOR_BLACK);
        $tcpdf->SetFont('Helvetica', 'B', InvoicePdfStyle::FONT_INVOICE_TITLE);
        $tcpdf->Cell(0, 7, $this->documentLabel($invoice), 0, 1);

        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        $tcpdf->Cell(0, 5, $this->numberLabel($invoice).' '.($invoice->number ?? $this->t('pdf_draft_no_number')), 0, 1);

        if ($invoice instanceof Quote && filled($invoice->title)) {
            $tcpdf->Ln(2);
            $tcpdf->SetFont('Helvetica', 'B', InvoicePdfStyle::FONT_BODY);
            $tcpdf->MultiCell(InvoicePdfStyle::COL_TOTAL_WIDTH, 0, (string) $invoice->title, 0, 'L');
        }

        if (filled($invoice->intro_text)) {
            $tcpdf->Ln(4);
            $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
            $tcpdf->MultiCell(InvoicePdfStyle::COL_TOTAL_WIDTH, 0, $this->text($invoice->intro_text), 0, 'L');
        }

        if (($invoice->tax_treatment ?? InvoiceTaxTreatment::Standard) === InvoiceTaxTreatment::ReverseCharge) {
            $tcpdf->Ln(2);
            $tcpdf->SetFont('Helvetica', 'B', 8);
            $tcpdf->MultiCell(InvoicePdfStyle::COL_TOTAL_WIDTH, 4, $this->t('pdf_reverse_charge'), 0, 'L');
        }

        $tcpdf->Ln(6);
    }

    /**
     * Lay a draft mark diagonally across the page.
     *
     * Drawn before the content so the text stays legible on top of it. The
     * point is that nobody can mistake a preview for the invoice itself: it
     * carries no number, it is not booked, and it cannot be paid.
     */
    public function renderDraftWatermark(TCPDF $tcpdf): void
    {
        $tcpdf->StartTransform();
        $tcpdf->Rotate(52, 105, 150);
        $tcpdf->SetFont('Helvetica', 'B', 64);
        $tcpdf->SetTextColor(228, 230, 228);
        $tcpdf->SetXY(0, 135);
        $tcpdf->Cell(210, 24, mb_strtoupper($this->t('pdf_draft')), 0, 0, 'C');
        $tcpdf->StopTransform();
        $tcpdf->SetTextColor(0, 0, 0);
    }

    /**
     * Stand in for the Swiss QR payment part, which a draft deliberately does
     * not carry: the payment reference is derived from the invoice number, so
     * it cannot exist before the number does, and a slip bearing a provisional
     * reference would invite a payment nobody can match.
     */
    public function renderDraftPaymentNote(TCPDF $tcpdf): void
    {
        $width = 210 - InvoicePdfStyle::MARGIN_LEFT - InvoicePdfStyle::MARGIN_RIGHT;
        $tcpdf->setPage($tcpdf->getNumPages());
        $this->ensureSpace($tcpdf, 26);
        $tcpdf->SetY(min(max($tcpdf->GetY() + 6, 240.0), InvoicePdfStyle::CONTENT_BOTTOM - 20));

        $tcpdf->setCellHeightRatio(1.25);
        $tcpdf->SetX(InvoicePdfStyle::MARGIN_LEFT);
        $tcpdf->SetDrawColor(190, 196, 190);
        $tcpdf->SetFont('Helvetica', 'B', 9);
        $tcpdf->SetTextColor(90, 96, 90);
        $tcpdf->Cell($width, 7, $this->t('pdf_draft_payment_title'), 'LTR', 1, 'L');

        $tcpdf->SetX(InvoicePdfStyle::MARGIN_LEFT);
        $tcpdf->SetFont('Helvetica', '', 8);
        $tcpdf->MultiCell($width, 11, $this->t('pdf_draft_payment_note'), 'LBR', 'L');

        $tcpdf->SetTextColor(0, 0, 0);
        $tcpdf->SetDrawColor(0, 0, 0);
    }

    public function renderLineItems(TCPDF $tcpdf, Invoice|Quote $invoice): void
    {
        $this->renderTableHeader($tcpdf);

        foreach ($invoice->lines as $line) {
            $this->renderLine($tcpdf, $line);
        }
    }

    public function renderTotals(TCPDF $tcpdf, Invoice|Quote $invoice, Organization $organization): void
    {
        $currency = $invoice->currency ?? 'CHF';
        $rates = $this->vatByRate($invoice);
        $rows = 2 + count($rates);
        $isInvoice = $invoice instanceof Invoice;
        $bank = $isInvoice ? $organization->defaultInvoicingBankAccount() : null;

        $this->ensureSpace($tcpdf, 8 + $rows * 6);
        $tcpdf->Ln(6);
        $top = $tcpdf->GetY();

        // Bank details on the left, as the payment slip carries them too.
        if ($bank !== null && filled($bank->iban ?: $bank->qr_iban)) {
            $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
            $bankLines = array_filter([
                ($organization->legal_name ?: $organization->name).':',
                $bank->bank_name ?: $bank->name,
                'IBAN: '.$this->formatIban((string) ($bank->iban ?: $bank->qr_iban)),
            ]);
            foreach ($bankLines as $line) {
                $tcpdf->SetX(InvoicePdfStyle::MARGIN_LEFT);
                $tcpdf->Cell(InvoicePdfStyle::BANK_WIDTH, 4.5, $line, 0, 1, 'L');
            }
        }

        $tcpdf->SetY($top);
        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        $this->totalsRow($tcpdf, $this->t('pdf_total_net'), $currency.' '.$this->money((string) $invoice->subtotal));
        foreach ($rates as $rate => $amount) {
            $this->totalsRow($tcpdf, $this->t('pdf_vat').' '.$rate.'%', $currency.' '.$this->money($amount));
        }

        $tcpdf->SetDrawColor(...InvoicePdfStyle::COLOR_RULE);
        $tcpdf->Line(InvoicePdfStyle::TOTALS_X, $tcpdf->GetY() + 0.5, 210 - InvoicePdfStyle::MARGIN_RIGHT, $tcpdf->GetY() + 0.5);
        $tcpdf->SetDrawColor(0, 0, 0);
        $tcpdf->Ln(1);
        $tcpdf->SetFont('Helvetica', 'B', InvoicePdfStyle::FONT_BODY + 1);
        $this->totalsRow($tcpdf, $this->t('pdf_total'), $currency.' '.$this->money((string) $invoice->total));
        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        $tcpdf->SetY(max($tcpdf->GetY(), $top + 15));

        if ($isInvoice && filled($invoice->payment_terms)) {
            $terms = is_numeric($invoice->payment_terms)
                ? trans('app.pdf_payable_within_days', ['days' => (int) $invoice->payment_terms], $this->locale)
                : (string) $invoice->payment_terms;
            $this->paragraph($tcpdf, $terms, 4);
        }

        if (filled($invoice->notes)) {
            $this->paragraph($tcpdf, $this->text((string) $invoice->notes), 4);
        }

        // The organisation's footer text speaks of payment; a quote leaves it out.
        if ($isInvoice && filled($organization->invoice_footer_text)) {
            $tcpdf->SetTextColor(...InvoicePdfStyle::COLOR_GRAY);
            $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_SMALL);
            $this->paragraph($tcpdf, $this->text((string) $organization->invoice_footer_text), 6);
            $tcpdf->SetTextColor(0, 0, 0);
            $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        }
    }

    /**
     * "Page n/N" at the bottom of every page drawn so far. Called once the
     * content is complete, before a QR payment page is added; that page keeps
     * its bottom free for the payment part but counts in N.
     */
    public function renderFooter(TCPDF $tcpdf): void
    {
        $current = $tcpdf->getPage();
        [$x, $y] = [$tcpdf->GetX(), $tcpdf->GetY()];
        $pages = $tcpdf->getNumPages();
        $breaks = $tcpdf->getAutoPageBreak();

        for ($page = 1; $page <= $pages; $page++) {
            // setPage() restores the page's own break setting, so switch it off after.
            $tcpdf->setPage($page);
            $tcpdf->SetAutoPageBreak(false);
            $tcpdf->SetXY(InvoicePdfStyle::MARGIN_LEFT, $tcpdf->getPageHeight() - 12);
            $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_SMALL);
            $tcpdf->SetTextColor(...InvoicePdfStyle::COLOR_LIGHT);
            $tcpdf->Cell(60, 4, $this->t('pdf_page').' '.$page.'/'.$tcpdf->getAliasNbPages(), 0, 0, 'L');
        }

        $tcpdf->SetTextColor(...InvoicePdfStyle::COLOR_BLACK);
        $tcpdf->setPage($current);
        $tcpdf->SetAutoPageBreak($breaks, 20);
        $tcpdf->SetXY($x, $y);
    }

    private function renderLogo(TCPDF $tcpdf, Organization $organization): void
    {
        $logoFullPath = $organization->logo_path ? Storage::disk('local')->path($organization->logo_path) : null;
        if (! $logoFullPath || ! file_exists($logoFullPath)) {
            return;
        }

        $logoWidth = (float) InvoicePdfStyle::LOGO_WIDTH;
        $logoHeight = (float) InvoicePdfStyle::LOGO_MAX_HEIGHT;
        $imageSize = @getimagesize($logoFullPath);
        if ($imageSize !== false && $imageSize[0] > 0 && $imageSize[1] > 0) {
            $aspectRatio = $imageSize[0] / $imageSize[1];
            $logoHeight = min(InvoicePdfStyle::LOGO_MAX_HEIGHT, $logoWidth / $aspectRatio);
            $logoWidth = $logoHeight * $aspectRatio;
        }

        $tcpdf->Image($logoFullPath, InvoicePdfStyle::LOGO_X, InvoicePdfStyle::LOGO_Y, $logoWidth, $logoHeight);
    }

    private function renderSender(TCPDF $tcpdf, Organization $organization): void
    {
        // The header text, when the organisation keeps one, is its sender block.
        $lines = filled($organization->invoice_header_text)
            ? explode("\n", $this->text((string) $organization->invoice_header_text))
            : array_values(array_filter([
                $organization->legal_name ?: $organization->name,
                $organization->address,
                $this->placeLine($organization),
                $organization->contact_email,
                $organization->vat_number ? $this->t('pdf_vat_uid_number').' '.$organization->vat_number : null,
            ]));

        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        $tcpdf->SetTextColor(...InvoicePdfStyle::COLOR_BLACK);
        $tcpdf->SetXY(InvoicePdfStyle::SENDER_X, InvoicePdfStyle::MARGIN_TOP);
        foreach ($lines as $line) {
            $tcpdf->SetX(InvoicePdfStyle::SENDER_X);
            $tcpdf->Cell(InvoicePdfStyle::SENDER_WIDTH, 4, trim($line), 0, 1, 'L');
        }
    }

    /** "CH - 6332 Hagendorn" */
    private function placeLine(Organization $organization): ?string
    {
        $place = trim(($organization->postal_code ?? '').' '.($organization->city ?? ''));

        return $place === '' ? null : ($organization->country ?: 'CH').' - '.$place;
    }

    private function renderRecipient(TCPDF $tcpdf, Invoice|Quote $invoice): void
    {
        $customer = $invoice->customerDetailsForDocument();
        if ($customer === null) {
            return;
        }

        $person = $customer['contact_person'] ?? null;
        $lines = array_values(array_filter([
            filled($customer['salutation'] ?? null) ? $customer['salutation'] : null,
            $customer['name'],
            $person['department'] ?? null,
            $person['name'] ?? null,
            $customer['address'] ?? null,
            trim(($customer['postal_code'] ?? '').' '.($customer['city'] ?? '')),
            $customer['country_name'] ?? null,
        ], fn ($line) => filled($line)));

        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        $tcpdf->SetXY(InvoicePdfStyle::CUSTOMER_X, InvoicePdfStyle::CUSTOMER_INFO_Y);
        foreach ($lines as $line) {
            foreach (explode("\n", $this->text((string) $line)) as $part) {
                $tcpdf->SetX(InvoicePdfStyle::CUSTOMER_X);
                $tcpdf->Cell(InvoicePdfStyle::CUSTOMER_WIDTH, 4, $part, 0, 1, 'L');
            }
        }

        if (filled($customer['vat_number'] ?? null)) {
            $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_SMALL);
            $tcpdf->SetTextColor(...InvoicePdfStyle::COLOR_GRAY);
            $tcpdf->SetX(InvoicePdfStyle::CUSTOMER_X);
            $tcpdf->Cell(InvoicePdfStyle::CUSTOMER_WIDTH, 4, $this->t('pdf_vat_number').': '.$customer['vat_number'], 0, 1, 'L');
            $tcpdf->SetTextColor(0, 0, 0);
        }
    }

    private function renderMeta(TCPDF $tcpdf, Invoice|Quote $invoice): void
    {
        $rows = [[$this->numberLabel($invoice), $invoice->number ?? $this->t('pdf_draft_no_number')]];
        $rows[] = [$this->t('pdf_date'), $invoice->issue_date->format('d.m.Y')];
        if ($invoice instanceof Quote) {
            if ($invoice->valid_until !== null) {
                $rows[] = [$this->t('pdf_valid_until'), $invoice->valid_until->format('d.m.Y')];
            }
        } elseif ($invoice->due_date !== null) {
            $rows[] = [$this->t('pdf_due_on'), $invoice->due_date->format('d.m.Y')];
        }
        if (filled($invoice->delivery_note)) {
            $rows[] = [$this->t('pdf_delivery_note'), (string) $invoice->delivery_note];
        }
        if ($invoice instanceof Invoice && filled($invoice->qr_reference)) {
            $rows[] = [$this->t('pdf_reference'), (string) $invoice->qr_reference];
        }

        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        $tcpdf->SetXY(InvoicePdfStyle::META_X, InvoicePdfStyle::META_Y);
        foreach ($rows as [$label, $value]) {
            $y = $tcpdf->GetY();
            $tcpdf->MultiCell(InvoicePdfStyle::META_LABEL_WIDTH, 0, $label, 0, 'L', false, 0, InvoicePdfStyle::META_X, $y);
            $tcpdf->MultiCell(InvoicePdfStyle::META_VALUE_WIDTH, 0, $value, 0, 'L', false, 1, InvoicePdfStyle::META_X + InvoicePdfStyle::META_LABEL_WIDTH, $y);
        }
    }

    private function renderTableHeader(TCPDF $tcpdf): void
    {
        $tcpdf->SetFont('Helvetica', 'B', InvoicePdfStyle::FONT_BODY);
        $tcpdf->SetX(InvoicePdfStyle::MARGIN_LEFT);
        $tcpdf->Cell(InvoicePdfStyle::COL_DESCRIPTION, 6, $this->t('pdf_description'), 0, 0, 'L');
        $tcpdf->Cell(InvoicePdfStyle::COL_VAT, 6, $this->t('pdf_vat'), 0, 0, 'L');
        $tcpdf->Cell(InvoicePdfStyle::COL_QUANTITY, 6, $this->t('pdf_quantity'), 0, 0, 'R');
        $tcpdf->Cell(InvoicePdfStyle::COL_UNIT_PRICE, 6, $this->t('pdf_price_per_unit'), 0, 0, 'R');
        $tcpdf->Cell(InvoicePdfStyle::COL_AMOUNT, 6, $this->t('pdf_line_total'), 0, 1, 'R');
        $tcpdf->Line(InvoicePdfStyle::MARGIN_LEFT, $tcpdf->GetY(), InvoicePdfStyle::MARGIN_LEFT + InvoicePdfStyle::COL_TOTAL_WIDTH, $tcpdf->GetY());
        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        $tcpdf->Ln(2);
    }

    private function renderLine(TCPDF $tcpdf, InvoiceLine|QuoteLine $line): void
    {
        $text = $this->text((string) $line->description);
        [$head, $rest] = array_pad(explode("\n", $text, 2), 2, '');
        $isText = $line->type === InvoiceLineType::Text;
        $width = InvoicePdfStyle::COL_DESCRIPTION - 2;

        $tcpdf->SetFont('Helvetica', $isText ? '' : 'B', InvoicePdfStyle::FONT_BODY);
        $headHeight = $tcpdf->getStringHeight($width, $head);
        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        $restHeight = $rest === '' ? 0.0 : $tcpdf->getStringHeight($width, $rest);
        $this->ensureSpace($tcpdf, $headHeight + min($restHeight, 20) + 2, true);

        $y = $tcpdf->GetY();
        $tcpdf->SetFont('Helvetica', $isText ? '' : 'B', InvoicePdfStyle::FONT_BODY);
        $tcpdf->MultiCell($width, 0, $head, 0, 'L', false, 1, InvoicePdfStyle::MARGIN_LEFT, $y);
        $afterHead = $tcpdf->GetY();

        if (! $isText) {
            $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
            $tcpdf->SetXY(InvoicePdfStyle::MARGIN_LEFT + InvoicePdfStyle::COL_DESCRIPTION, $y);
            $tcpdf->Cell(InvoicePdfStyle::COL_VAT, 4.5, $line->vatRate ? $this->rate((string) $line->vatRate->rate).'%' : '', 0, 0, 'L');
            [$quantity, $price, $total] = $this->lineFigures($line);
            $tcpdf->Cell(InvoicePdfStyle::COL_QUANTITY, 4.5, $quantity, 0, 0, 'R');
            $tcpdf->Cell(InvoicePdfStyle::COL_UNIT_PRICE, 4.5, $price, 0, 0, 'R');
            $tcpdf->Cell(InvoicePdfStyle::COL_AMOUNT, 4.5, $total, 0, 1, 'R');
        }

        $tcpdf->SetY($afterHead);
        $tcpdf->SetFont('Helvetica', '', InvoicePdfStyle::FONT_BODY);
        foreach ($rest === '' ? [] : explode("\n", $rest) as $part) {
            $this->ensureSpace($tcpdf, $tcpdf->getStringHeight($width, $part), true);
            $tcpdf->MultiCell($width, 0, $part, 0, 'L', false, 1, InvoicePdfStyle::MARGIN_LEFT);
        }

        $tcpdf->Ln(3);
    }

    /**
     * Quantity, price per unit and total of a line as printed.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function lineFigures(InvoiceLine|QuoteLine $line): array
    {
        if ($line->type === InvoiceLineType::Discount && $line->discount_type === 'percentage') {
            return ['', $this->rate((string) $line->unit_price).'%', '-'.$this->money((string) $line->amount)];
        }

        $total = Money::multiply2((string) $line->quantity, (string) $line->unit_price);
        $price = $this->money((string) $line->unit_price).(filled($line->unit) ? ' / '.$line->unit : '');

        return [
            $this->quantity((string) $line->quantity),
            $price,
            ($line->type === InvoiceLineType::Discount ? '-' : '').$this->money($total),
        ];
    }

    /**
     * VAT per rate, discounts subtracted; rates without VAT are left out.
     *
     * @return array<string, string> rate label => amount
     */
    private function vatByRate(Invoice|Quote $invoice): array
    {
        $byRate = [];
        foreach ($invoice->lines as $line) {
            if ($line->vatRate === null || Money::isZero((string) $line->vatRate->rate)) {
                continue;
            }
            $label = $this->rate((string) $line->vatRate->rate);
            $amount = (string) $line->vat_amount;
            $byRate[$label] = $line->type === InvoiceLineType::Discount
                ? Money::subtract($byRate[$label] ?? '0.00', $amount)
                : Money::add($byRate[$label] ?? '0.00', $amount);
        }

        return array_filter($byRate, fn (string $amount) => ! Money::isZero($amount));
    }

    private function totalsRow(TCPDF $tcpdf, string $label, string $amount): void
    {
        $tcpdf->SetX(InvoicePdfStyle::TOTALS_X);
        $tcpdf->Cell(InvoicePdfStyle::TOTALS_LABEL_WIDTH, 5.5, $label, 0, 0, 'L');
        $tcpdf->Cell(InvoicePdfStyle::TOTALS_AMOUNT_WIDTH, 5.5, $amount, 0, 1, 'R');
    }

    private function paragraph(TCPDF $tcpdf, string $text, float $gap): void
    {
        $this->ensureSpace($tcpdf, $gap + $tcpdf->getStringHeight(InvoicePdfStyle::COL_TOTAL_WIDTH, $text));
        $tcpdf->Ln($gap);
        $tcpdf->MultiCell(InvoicePdfStyle::COL_TOTAL_WIDTH, 0, $text, 0, 'L', false, 1, InvoicePdfStyle::MARGIN_LEFT);
    }

    /** Starts a new page when the next block would not fit, repeating the table head inside the table. */
    private function ensureSpace(TCPDF $tcpdf, float $height, bool $inTable = false): void
    {
        if ($tcpdf->GetY() + $height <= InvoicePdfStyle::CONTENT_BOTTOM) {
            return;
        }

        $tcpdf->AddPage();
        $tcpdf->SetY(InvoicePdfStyle::MARGIN_TOP + 5);
        if ($inTable) {
            $this->renderTableHeader($tcpdf);
        }
    }

    private function documentLabel(Invoice|Quote $invoice): string
    {
        return match (true) {
            $invoice instanceof Quote => $this->t('pdf_quote'),
            $invoice->type === InvoiceType::CreditNote => $this->t('pdf_credit_note'),
            default => $this->t('pdf_invoice'),
        };
    }

    private function numberLabel(Invoice|Quote $invoice): string
    {
        return $invoice instanceof Quote ? $this->t('pdf_quote_number') : $this->t('pdf_invoice_number');
    }

    /** Swiss amount format: 1'234.50. */
    private function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', "'");
    }

    /** A quantity without trailing zeros: 2, 1.5, 0.25. */
    private function quantity(string $quantity): string
    {
        $formatted = number_format((float) $quantity, 2, '.', "'");

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /** A VAT rate as printed: 8.1, 2.6, 0. */
    private function rate(string $rate): string
    {
        return $this->quantity($rate);
    }

    private function formatIban(string $iban): string
    {
        return trim(chunk_split(preg_replace('/\s+/', '', $iban) ?? $iban, 4, ' '));
    }

    private function text(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
