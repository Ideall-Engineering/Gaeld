<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Invoicing\Services\SwissQrInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Seeing a draft before it becomes an invoice — on screen and over the API.
 */
class InvoiceDraftPreviewTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->organization->update([
            'legal_name' => 'Ideall GmbH',
            'address' => 'Bahnhofstrasse 1',
            'postal_code' => '8001',
            'city' => 'Zürich',
            'country' => 'CH',
            'locale' => 'de',
        ]);

        $this->customer = Contact::create([
            'organization_id' => $this->organization->id,
            'name' => 'Client AG',
            'address' => 'Lagerstrasse 5',
            'postal_code' => '8004',
            'city' => 'Zürich',
            'country' => 'CH',
        ]);

        VatRate::create([
            'organization_id' => $this->organization->id,
            'name' => 'Standard',
            'rate' => 8.10,
            'code' => 'NORMAL',
            'is_default' => true,
        ]);
    }

    public function test_a_draft_can_be_previewed_on_screen(): void
    {
        $invoice = $this->draft();

        $response = $this->actAsOrg()->get("/invoices/{$invoice->id}/pdf-preview");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="invoice-draft-'.$invoice->id.'.pdf"');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
    }

    public function test_a_preview_writes_nothing(): void
    {
        $this->withQrBankAccount();
        $invoice = $this->draft();

        $this->actAsOrg()->get("/invoices/{$invoice->id}/pdf-preview")->assertOk();

        $fresh = $invoice->fresh();
        $this->assertNull(
            $fresh->qr_reference,
            'A preview used to persist a reference of twenty zeros, which the finalisation then kept.',
        );
        $this->assertNull($fresh->number, 'The number belongs to the finalisation.');
        $this->assertSame(InvoiceStatus::Draft, $fresh->status);
    }

    public function test_a_draft_can_be_previewed_before_any_bank_account_exists(): void
    {
        // No QR-IBAN anywhere: the preview carries no payment part, so it has
        // nothing to validate and must not ask for one.
        $invoice = $this->draft();

        $this->actAsOrg()->get("/invoices/{$invoice->id}/pdf-preview")->assertOk();
    }

    public function test_the_preview_leaves_out_the_payment_part(): void
    {
        $this->withQrBankAccount();
        $invoice = $this->draft();

        $preview = app(GenerateQrInvoicePdfAction::class)
            ->execute($invoice, $this->organization, 'de', preview: true);

        $finalised = $this->finalisedCopy();
        $full = app(GenerateQrInvoicePdfAction::class)
            ->execute($finalised, $this->organization, 'de');

        $this->assertSame(1, $this->pageCount($preview), 'A draft is one page.');
        $this->assertSame(2, $this->pageCount($full), 'The finished invoice adds the QR payment slip page.');
    }

    public function test_the_preview_says_on_the_page_that_it_is_a_draft(): void
    {
        $invoice = $this->draft();

        $text = $this->pdfText(app(GenerateQrInvoicePdfAction::class)
            ->execute($invoice, $this->organization, 'de', preview: true));

        $this->assertStringContainsString('ENTWURF', $text, 'The mark across the page.');
        $this->assertStringContainsString('Entwurf', $text, 'In place of the number, and above the payment note.');
        $this->assertStringContainsString('Zahlteil', $text, 'Why there is no payment part.');
        $this->assertStringNotContainsString('Referenz', $text, 'There is no payment reference to print.');
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\\s*/Page[^s]#', $pdf);
    }

    /**
     * The text TCPDF drew, read back out of the deflated content streams.
     */
    private function pdfText(string $pdf): string
    {
        $chunks = [];

        preg_match_all('#stream\\r?\\n(.*?)\\r?\\nendstream#s', $pdf, $matches);

        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $chunks[] = $inflated === false ? $stream : $inflated;
        }

        return implode('\\n', $chunks);
    }

    public function test_the_finished_pdf_refuses_a_draft(): void
    {
        $this->withQrBankAccount();
        $invoice = $this->draft();

        $this->actAsOrg()->from("/invoices/{$invoice->id}")
            ->get("/invoices/{$invoice->id}/qr-pdf")
            ->assertRedirect("/invoices/{$invoice->id}");

        $this->assertNull($invoice->fresh()->qr_reference);
    }

    public function test_the_preview_refuses_anything_already_issued(): void
    {
        $invoice = $this->finalisedCopy();

        $this->actAsOrg()->from("/invoices/{$invoice->id}")
            ->get("/invoices/{$invoice->id}/pdf-preview")
            ->assertRedirect("/invoices/{$invoice->id}");
    }

    public function test_the_api_serves_a_draft_preview_and_refuses_it_on_the_finished_endpoint(): void
    {
        $this->withQrBankAccount();
        $invoice = $this->draft();
        $token = $this->apiToken();

        $this->withToken($token)
            ->get("/api/v1/invoices/{$invoice->id}/pdf/preview")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->withToken($token)
            ->getJson("/api/v1/invoices/{$invoice->id}/pdf")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_not_finalised');

        $this->assertNull($invoice->fresh()->qr_reference);
    }

    public function test_the_api_preview_refuses_anything_already_issued(): void
    {
        $invoice = $this->finalisedCopy();

        $this->withToken($this->apiToken())
            ->getJson("/api/v1/invoices/{$invoice->id}/pdf/preview")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_not_draft');
    }

    public function test_a_payment_reference_is_not_invented_without_a_number(): void
    {
        $this->withQrBankAccount();
        $invoice = $this->draft();

        app(SwissQrInvoiceService::class)->ensureQrReference($invoice, $this->organization);

        $this->assertNull(
            $invoice->qr_reference,
            'The reference encodes the invoice number, so without one there is nothing to encode.',
        );
    }

    private function withQrBankAccount(): void
    {
        BankAccount::create([
            'organization_id' => $this->organization->id,
            'name' => 'QR-Konto',
            'currency' => 'CHF',
            'qr_iban' => 'CH4431999123000889012',
            'is_default_for_invoicing' => true,
            'is_active' => true,
        ]);
    }

    private function draft(): Invoice
    {
        return $this->makeInvoice(null, InvoiceStatus::Draft);
    }

    private function finalisedCopy(): Invoice
    {
        $invoice = $this->makeInvoice('INV-2026-0001', InvoiceStatus::Sent);
        $invoice->update([
            'qr_reference' => app(SwissQrInvoiceService::class)->generateQrReference('00000', '1'),
            'qr_type' => 'QRR',
        ]);

        return $invoice->fresh();
    }

    private function makeInvoice(?string $number, InvoiceStatus $status): Invoice
    {
        $invoice = Invoice::create([
            'organization_id' => $this->organization->id,
            'customer_id' => $this->customer->id,
            'number' => $number,
            'status' => $status,
            'issue_date' => '2026-09-12',
            'due_date' => '2026-10-12',
            'currency' => 'CHF',
            'subtotal' => '150.00',
            'vat_amount' => '12.15',
            'total' => '162.15',
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Beratung September',
            'quantity' => '1.00',
            'unit_price' => '150.00',
            'amount' => '150.00',
            'vat_amount' => '12.15',
            'sort_order' => 0,
        ]);

        return $invoice->fresh();
    }

    private function apiToken(): string
    {
        $result = $this->user->createToken('draft-preview-test', ['*']);
        $result->accessToken->update([
            'organization_id' => $this->organization->id,
            'type' => TokenType::Personal,
        ]);

        return $result->plainTextToken;
    }
}
