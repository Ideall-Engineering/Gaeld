<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Actions\RefreshInvoiceCustomerSnapshotAction;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Exceptions\QrBillValidationException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Invoicing\Services\InstallmentPlanService;
use App\Domains\Invoicing\Services\InvoicePdfRenderer;
use App\Domains\Invoicing\Services\SwissQrInvoiceService;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * End-to-end coverage for the invoice PDF generation pipeline
 * (GenerateQrInvoicePdfAction -> InvoicePdfRenderer + SwissQrInvoiceService
 * + TCPDF). Previously this entire pipeline had zero real execution in the
 * test suite — GenerateQrInvoicePdfAction was only ever mocked, so a TCPDF
 * API break, a null-relation crash, or a malformed QR bill for edge-case
 * invoice data (credit notes, no VAT, long descriptions, non-Latin locale
 * text) would not be caught by CI.
 */
class GenerateQrInvoicePdfActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Contact $customer;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Test GmbH',
            'legal_name' => 'Test GmbH',
            'address' => 'Bahnhofstrasse 1',
            'postal_code' => '8001',
            'city' => 'Zürich',
            'country' => 'CH',
            'currency' => 'CHF',
        ]);

        BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'QR-Bill account',
            'currency' => 'CHF',
            'qr_iban' => 'CH4431999123000889012',
            'is_default_for_invoicing' => true,
            'is_active' => true,
        ]);

        $this->customer = Contact::create([
            'organization_id' => $this->org->id,
            'name' => 'Client AG',
            'address' => 'Lagerstrasse 5',
            'postal_code' => '8004',
            'city' => 'Zürich',
            'country' => 'CH',
        ]);

        $this->vatRate = VatRate::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard',
            'rate' => 8.10,
            'code' => 'NORMAL',
            'is_default' => true,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return [
            'english' => ['en'],
            'german' => ['de'],
            'french' => ['fr'],
            'italian' => ['it'],
        ];
    }

    #[DataProvider('locales')]
    public function test_renders_a_valid_pdf_for_a_standard_invoice_in_every_supported_locale(string $locale): void
    {
        $invoice = $this->makeInvoice('INV-PDF-'.$locale);

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org, $locale);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf), 'Rendered PDF should contain the invoice content + QR payment slip pages');
    }

    public function test_after_a_part_payment_the_slip_asks_for_the_rest(): void
    {
        $invoice = $this->makeInvoice('INV-PDF-PART');
        $invoice->payments()->create([
            'organization_id' => $this->org->id,
            'amount' => '100.00',
            'payment_date' => '2026-01-20',
            'payment_method' => 'bank',
        ]);

        $qrBill = app(SwissQrInvoiceService::class)->buildQrBill($invoice->fresh(), $this->org);
        $this->assertSame(62.15, $qrBill->getPaymentAmountInformation()->getAmount());

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice->fresh(), $this->org, 'de');
        $this->assertStringStartsWith('%PDF-', $pdf);

        // Settled, the invoice keeps the total it was issued with.
        $invoice->update(['status' => 'paid']);
        $qrBill = app(SwissQrInvoiceService::class)->buildQrBill($invoice->fresh(), $this->org);
        $this->assertSame(162.15, $qrBill->getPaymentAmountInformation()->getAmount());
    }

    private function withPlan(string $number): Invoice
    {
        $invoice = $this->makeInvoice($number, unitPrice: '1500.00');
        foreach ([['2026-02-15', '600.00'], ['2026-03-15', '600.00'], ['2026-04-15', '421.50']] as $index => [$date, $amount]) {
            $invoice->installments()->create(['organization_id' => $this->org->id, 'position' => $index + 1, 'due_date' => $date, 'amount' => $amount]);
        }
        app(SwissQrInvoiceService::class)->ensureInstallmentReferences($invoice, $this->org);

        return $invoice->fresh();
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
    }

    public function test_each_open_rate_gets_its_slip(): void
    {
        $invoice = $this->withPlan('INV-PDF-PLAN-1');
        $invoice->payments()->create(['organization_id' => $this->org->id, 'amount' => '900.00', 'payment_date' => '2026-02-10', 'payment_method' => 'bank']);
        $invoice = $invoice->fresh();

        $slips = app(InstallmentPlanService::class)->slips($invoice);
        $this->assertSame([2, 3], array_map(fn (array $slip) => $slip['installment']->position, $slips));
        $this->assertSame(['300.00', '421.50'], array_column($slips, 'amount'));

        $qrBill = app(SwissQrInvoiceService::class)->buildQrBill($invoice, $this->org, $slips[0]['installment'], $slips[0]['amount']);
        $this->assertSame(300.0, $qrBill->getPaymentAmountInformation()->getAmount());
        $this->assertSame($slips[0]['installment']->qr_reference, $qrBill->getPaymentReference()->getReference());
        $this->assertNotSame($invoice->qr_reference, $qrBill->getPaymentReference()->getReference());

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org, 'de');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(3, $this->pageCount($pdf));
    }

    public function test_a_paid_plan_prints_every_rate(): void
    {
        $invoice = $this->withPlan('INV-PDF-PLAN-2');
        $invoice->update(['status' => 'paid']);

        $slips = app(InstallmentPlanService::class)->slips($invoice->fresh());

        $this->assertSame(['600.00', '600.00', '421.50'], array_column($slips, 'amount'));
        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice->fresh(), $this->org, 'de');
        $this->assertSame(4, $this->pageCount($pdf));
    }

    public function test_an_invoice_without_a_plan_keeps_one_slip(): void
    {
        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($this->makeInvoice('INV-PDF-ONE'), $this->org, 'de');

        $this->assertSame(2, $this->pageCount($pdf));
    }

    public function test_missing_due_date_does_not_cause_a_renderer_error(): void
    {
        $invoice = $this->makeInvoice('INV-PDF-NO-DUE-DATE');
        $invoice->update(['due_date' => null]);

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org, 'en');

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_regenerating_the_pdf_keeps_the_snapshot_until_it_is_explicitly_refreshed(): void
    {
        $invoice = $this->makeInvoice('INV-PDF-CONTACT-UPDATE');
        $snapshot = $this->customer->toInvoiceSnapshot();
        $invoice->update(['customer_snapshot' => $snapshot]);
        $renderedAddresses = [];

        $this->partialMock(InvoicePdfRenderer::class, function (MockInterface $mock) use (&$renderedAddresses): void {
            $mock->shouldReceive('renderInvoiceHeader')
                ->times(3)
                ->withArgs(function (\TCPDF $tcpdf, Invoice $candidate, Organization $organization) use (&$renderedAddresses): bool {
                    $renderedAddresses[] = $candidate->customerDetailsForDocument();

                    return true;
                })
                ->passthru();
        });
        $action = app(GenerateQrInvoicePdfAction::class);

        $firstPdf = $action->execute($invoice, $this->org, 'fr');
        $this->customer->update([
            'salutation' => 'Monsieur',
            'name' => 'Maël Exemple',
            'address' => "Rue de l'Exemple 10",
            'postal_code' => '2000',
            'city' => 'Neuchâtel',
            'country_name' => 'Suisse',
        ]);
        $secondPdf = $action->execute($invoice, $this->org, 'fr');

        $this->assertSame($snapshot, $renderedAddresses[1]);
        $this->assertSame($snapshot, $invoice->fresh()->customer_snapshot);

        $invoice = app(RefreshInvoiceCustomerSnapshotAction::class)->execute($invoice);
        $thirdPdf = $action->execute($invoice, $this->org, 'fr');

        $this->assertStringStartsWith('%PDF-', $firstPdf);
        $this->assertStringStartsWith('%PDF-', $secondPdf);
        $this->assertStringStartsWith('%PDF-', $thirdPdf);
        $this->assertSame('Lagerstrasse 5', $renderedAddresses[0]['address']);
        $this->assertSame('Monsieur', $renderedAddresses[2]['salutation']);
        $this->assertSame('Maël Exemple', $renderedAddresses[2]['name']);
        $this->assertSame("Rue de l'Exemple 10", $renderedAddresses[2]['address']);
        $this->assertSame('2000', $renderedAddresses[2]['postal_code']);
        $this->assertSame('Neuchâtel', $renderedAddresses[2]['city']);
        $this->assertSame('Suisse', $renderedAddresses[2]['country_name']);
        $this->assertSame($this->customer->toInvoiceSnapshot(), $invoice->fresh()->customer_snapshot);
    }

    public function test_credit_notes_cannot_generate_a_qr_payment_slip(): void
    {
        // Documents existing, intentional behavior: a QR-bill is a payment
        // request and cannot carry a negative amount, so credit notes
        // (negative totals) fail QR-bill validation. The controller
        // (InvoiceDocumentController::downloadQrPdf) catches this and shows
        // a friendly flash message via QrBillValidationMessageFormatter — it
        // is not a crash. This test locks in that the failure mode is a
        // handled QrBillValidationException, not an uncaught TCPDF/library error.
        $invoice = $this->makeInvoice('CN-PDF-001', InvoiceType::CreditNote, unitPrice: '-150.00');

        $this->expectException(QrBillValidationException::class);

        app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org, 'en');
    }

    public function test_renders_without_a_vat_rate_on_the_line(): void
    {
        $invoice = $this->makeInvoice('INV-PDF-NOVAT', withVat: false);

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org, 'en');

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_renders_a_qr_invoice_without_a_customer_postal_code_or_city(): void
    {
        $this->customer->update([
            'address' => null,
            'postal_code' => null,
            'city' => null,
        ]);
        $invoice = $this->makeInvoice('INV-PDF-NO-DEBTOR-ADDRESS');

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org, 'fr');

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_renders_with_a_long_line_description_without_throwing(): void
    {
        $invoice = $this->makeInvoice('INV-PDF-LONGDESC', description: str_repeat('Consulting services rendered over multiple engagements. ', 5));

        $pdf = app(GenerateQrInvoicePdfAction::class)->execute($invoice, $this->org, 'en');

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    private static int $referenceSequence = 1;

    private function makeInvoice(
        string $number,
        InvoiceType $type = InvoiceType::Invoice,
        string $unitPrice = '150.00',
        bool $withVat = true,
        string $description = 'Consulting services',
    ): Invoice {
        $qrService = app(SwissQrInvoiceService::class);

        $subtotal = ltrim($unitPrice, '-');
        $vatAmount = $withVat ? bcmul($subtotal, '0.081', 2) : '0.00';
        $total = bcadd($subtotal, $vatAmount, 2);
        if (str_starts_with($unitPrice, '-')) {
            $subtotal = "-{$subtotal}";
            $vatAmount = "-{$vatAmount}";
            $total = "-{$total}";
        }

        $invoice = Invoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'type' => $type,
            'number' => $number,
            'status' => 'sent',
            'issue_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'currency' => 'CHF',
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'qr_reference' => $qrService->generateQrReference('00000', (string) self::$referenceSequence++),
            'qr_type' => 'QRR',
            'qr_iban' => 'CH4431999123000889012',
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => $description,
            'quantity' => '1.00',
            'unit_price' => $unitPrice,
            'amount' => $subtotal,
            'vat_rate_id' => $withVat ? $this->vatRate->id : null,
            'vat_amount' => $vatAmount,
        ]);

        return $invoice->fresh(['customer', 'lines.vatRate']);
    }
}
