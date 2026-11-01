<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceInstallment;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\QrCode\QrCode;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;

/**
 * Builds Swiss QR-bill payment slips for invoices.
 *
 * Generates the QR code data structure, structured creditor address,
 * and returns the QR payment part as a rendered PNG or PDF.
 */
class SwissQrInvoiceService
{
    private const DEFAULT_COUNTRY = 'CH';

    private const DEFAULT_CURRENCY = 'CHF';

    private const ALLOWED_CURRENCIES = ['CHF', 'EUR'];

    // ──────────────────────────────────────────────────────────────
    //  QR Bill Building
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate a QR reference (QRR) for an invoice.
     *
     * Uses the invoice number to build a deterministic 27-digit reference.
     */
    public function generateQrReference(string $customerIdentification, string $invoiceNumber): string
    {
        $referenceNumber = preg_replace('/[^0-9]/', '', $invoiceNumber);
        $referenceNumber = str_pad($referenceNumber, 20, '0', STR_PAD_LEFT);

        return QrPaymentReferenceGenerator::generate(
            $customerIdentification,
            $referenceNumber,
        );
    }

    /**
     * Build a QrBill object from invoice + organization data. For a rate of
     * a payment plan (spec 013) the slip carries that rate's reference and
     * the given amount.
     */
    public function buildQrBill(Invoice $invoice, Organization $organization, ?InvoiceInstallment $installment = null, ?string $amount = null): QrBill
    {
        $invoice->loadMissing(['customer']);

        $this->ensureQrReference($invoice, $organization);
        if ($installment !== null && $installment->qr_reference === null) {
            // A QR-IBAN set after finalizing: the rates get theirs now, as
            // the invoice does above.
            $this->ensureInstallmentReferences($invoice, $organization);
            $installment->refresh();
        }

        $qrBill = QrBill::create();

        $iban = $this->resolvePaymentIban($invoice, $organization);

        $this->setCreditorInfo($qrBill, $organization, $iban);
        $this->setDebtorInfo($qrBill, $invoice);
        $this->setPaymentAmount($qrBill, $invoice, $amount ?? ($installment !== null ? (string) $installment->amount : null));

        $this->setPaymentReference($qrBill, $invoice, $iban, $installment);

        $information = $invoice->number ?? '';
        if ($installment !== null) {
            $count = $invoice->installments()->count();
            $information = trim($information.' '.trans('app.qr_installment', ['position' => $installment->position, 'count' => $count]));
        }
        $qrBill->setAdditionalInformation(AdditionalInformation::create($information));

        return $qrBill;
    }

    // ──────────────────────────────────────────────────────────────
    //  Bill Component Builders
    // ──────────────────────────────────────────────────────────────

    /**
     * Auto-generate and persist a QR reference when the IBAN is a QR-IBAN
     * and the invoice does not already have one.
     */
    public function ensureQrReference(Invoice $invoice, Organization $organization): void
    {
        if ($invoice->qr_reference) {
            return;
        }

        $iban = $invoice->qr_iban ?? $organization->qr_iban ?? '';
        if (! $this->isQrIban($iban)) {
            return;
        }

        // The reference encodes the invoice number, so without one there is
        // nothing to encode. Generating anyway produced a reference of twenty
        // zeros that stuck — this method returns early once a reference exists,
        // so the finalisation would never have corrected it.
        if (blank($invoice->number)) {
            return;
        }

        $customerIdentification = str_pad(
            (string) ($invoice->customer_id ? crc32((string) $invoice->customer_id) % 100000 : 0),
            5,
            '0',
            STR_PAD_LEFT,
        );

        $qrReference = $this->generateQrReference(
            $customerIdentification,
            $invoice->number ?? '',
        );

        $invoice->updateQuietly([
            'qr_reference' => $qrReference,
            'qr_type' => 'QRR',
        ]);
    }

    /**
     * Gives each rate of a payment plan its own QR reference (spec 013): the
     * digits of the invoice number on 18 places and the rate on two, with
     * the customer part of the invoice's own reference.
     *
     * @throws InvalidInvoiceStateException when a reference is already taken
     */
    public function ensureInstallmentReferences(Invoice $invoice, Organization $organization): void
    {
        $iban = $invoice->qr_iban ?? $organization->qr_iban ?? '';
        if (! $this->isQrIban($iban) || blank($invoice->number)) {
            return;
        }

        $digits = substr((string) preg_replace('/[^0-9]/', '', $invoice->number), -18);
        $customerIdentification = str_pad(
            (string) ($invoice->customer_id ? crc32((string) $invoice->customer_id) % 100000 : 0),
            5,
            '0',
            STR_PAD_LEFT,
        );

        foreach ($invoice->installments()->whereNull('qr_reference')->get() as $installment) {
            $reference = QrPaymentReferenceGenerator::generate(
                $customerIdentification,
                str_pad($digits, 18, '0', STR_PAD_LEFT).str_pad((string) $installment->position, 2, '0', STR_PAD_LEFT),
            );

            $taken = Invoice::withoutGlobalScopes()
                ->where('organization_id', $invoice->organization_id)
                ->where('qr_reference', $reference)
                ->exists()
                || InvoiceInstallment::withoutGlobalScopes()
                    ->where('organization_id', $invoice->organization_id)
                    ->where('qr_reference', $reference)
                    ->exists();
            if ($taken) {
                throw new InvalidInvoiceStateException(__('app.payment_plan_reference_taken'));
            }

            $installment->updateQuietly(['qr_reference' => $reference]);
        }
    }

    /**
     * Determine whether the given IBAN is a Swiss QR-IBAN (IID 30000–31999).
     */
    private function isQrIban(string $iban): bool
    {
        $iban = preg_replace('/\s+/', '', $iban);

        if (! preg_match('/^(CH|LI)/i', $iban)) {
            return false;
        }

        $iid = (int) substr($iban, 4, 5);

        return $iid >= 30000 && $iid <= 31999;
    }

    private function resolvePaymentIban(Invoice $invoice, Organization $organization): string
    {
        $bankAccount = $organization->defaultInvoicingBankAccount();

        return (string) ($invoice->qr_iban ?: $bankAccount?->qr_iban ?: $bankAccount?->iban ?: '');
    }

    private function setCreditorInfo(QrBill $qrBill, Organization $organization, string $iban): void
    {
        $creditorAddress = StructuredAddress::createWithStreet(
            $organization->legal_name ?? $organization->name,
            $organization->address ?? '',
            null,
            $organization->postal_code ?? '',
            $organization->city ?? '',
            $organization->country ?? self::DEFAULT_COUNTRY,
        );

        $qrBill->setCreditor($creditorAddress);

        $qrBill->setCreditorInformation(
            CreditorInformation::create($iban),
        );
    }

    private function setDebtorInfo(QrBill $qrBill, Invoice $invoice): void
    {
        $customerDetails = $invoice->customerDetailsForDocument();

        if ($customerDetails === null) {
            return;
        }

        // The ultimate debtor is optional on a Swiss QR bill. Do not build an
        // invalid structured address when a contact has no postal code or city.
        if (blank(data_get($customerDetails, 'postal_code'))
            || blank(data_get($customerDetails, 'city'))
            || blank(data_get($customerDetails, 'country'))
        ) {
            return;
        }

        $debtorAddress = StructuredAddress::createWithStreet(
            $customerDetails['name'],
            $customerDetails['address'] ?? '',
            null,
            $customerDetails['postal_code'] ?? '',
            $customerDetails['city'] ?? '',
            $customerDetails['country'],
        );

        $qrBill->setUltimateDebtor($debtorAddress);
    }

    private function setPaymentAmount(QrBill $qrBill, Invoice $invoice, ?string $amount = null): void
    {
        $currency = $invoice->currency ?? self::DEFAULT_CURRENCY;
        if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            $currency = self::DEFAULT_CURRENCY;
        }

        $qrBill->setPaymentAmountInformation(
            PaymentAmountInformation::create($currency, (float) ($amount ?? $this->amountToPay($invoice))),
        );
    }

    /**
     * What the slip asks for: the open rest once part of an open invoice is
     * paid, otherwise the total. A settled invoice keeps its total, as it
     * was issued.
     */
    public function amountToPay(Invoice $invoice): string
    {
        if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true)) {
            return (string) $invoice->total;
        }

        $due = $invoice->amountDue();

        return Money::isPositive($due) ? $due : (string) $invoice->total;
    }

    private function setPaymentReference(QrBill $qrBill, Invoice $invoice, string $iban, ?InvoiceInstallment $installment = null): void
    {
        $qrType = $invoice->qr_type ?? 'QRR';
        $reference = $installment !== null ? $installment->qr_reference : $invoice->qr_reference;

        if ($this->isQrIban($iban) && $qrType === 'QRR' && $reference) {
            $refType = PaymentReference::TYPE_QR;
        } elseif ($installment === null && $qrType === 'SCOR' && $reference) {
            $refType = PaymentReference::TYPE_SCOR;
        } else {
            $refType = PaymentReference::TYPE_NON;
        }

        $qrBill->setPaymentReference(
            PaymentReference::create($refType, $refType === PaymentReference::TYPE_NON ? null : $reference),
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Output & Validation
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate a PNG data URI for the invoice QR code.
     */
    public function generateQrImage(Invoice $invoice, Organization $organization): string
    {
        $qrBill = $this->buildQrBill($invoice, $organization);

        return $qrBill->getQrCode()->getDataUri(QrCode::FILE_FORMAT_PNG);
    }

    /**
     * Get QR bill violations (validation errors), each formatted as
     * "<propertyPath>: <message>" so callers can surface the exact field
     * that failed validation rather than a vague summary.
     *
     * @return array<string>
     */
    public function validate(Invoice $invoice, Organization $organization): array
    {
        $qrBill = $this->buildQrBill($invoice, $organization);

        return array_map(
            function ($violation): string {
                $message = (string) $violation->getMessage();
                $path = (string) $violation->getPropertyPath();

                return $path !== '' ? $path.': '.$message : $message;
            },
            iterator_to_array($qrBill->getViolations()),
        );
    }
}
