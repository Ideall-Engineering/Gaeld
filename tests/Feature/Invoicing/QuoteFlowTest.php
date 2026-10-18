<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Quotes on the web: write one, send it, record the answer, turn it into an
 * invoice draft with the same lines and totals.
 */
class QuoteFlowTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->vatRate = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Schule Zug']);
    }

    /** @param array<string, mixed> $overrides */
    private function storeQuote(array $overrides = []): Quote
    {
        $this->actAsOrg()->post('/quotes', [
            'customer_id' => $this->customer->id,
            'title' => 'Workshop KI',
            'issue_date' => '2026-10-18',
            'valid_until' => '2026-11-17',
            'currency' => 'CHF',
            'payment_terms' => '30',
            'lines' => [
                ['type' => 'item', 'description' => 'Workshop, halber Tag', 'quantity' => 2, 'unit_price' => 1000, 'vat_rate_id' => $this->vatRate->id],
                ['type' => 'discount', 'discount_type' => 'percentage', 'description' => 'Schulrabatt', 'quantity' => 1, 'unit_price' => 10, 'vat_rate_id' => $this->vatRate->id],
                ['type' => 'text', 'description' => 'Material inbegriffen'],
            ],
            ...$overrides,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Quote::orderByDesc('number')->firstOrFail();
    }

    public function test_a_quote_is_numbered_and_totalled_like_an_invoice(): void
    {
        $quote = $this->storeQuote();

        $this->assertSame('OF-2026-001', $quote->number);
        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertSame('1800.00', $quote->subtotal);
        $this->assertSame('145.80', $quote->vat_amount);
        $this->assertSame('1945.80', $quote->total);
        $this->assertCount(3, $quote->lines);
        $this->assertSame('Schule Zug', $quote->customer_snapshot['name']);

        $this->assertSame('OF-2026-002', $this->storeQuote()->number);
        $this->assertSame(0, Invoice::count());
    }

    public function test_the_pages_and_the_pdf_render(): void
    {
        $quote = $this->storeQuote();

        $this->actAsOrg()->get('/quotes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quotes/Index')->has('quotes.data', 1)->where('can.create', true));
        $this->actAsOrg()->get("/quotes/{$quote->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quotes/Show')
                ->where('quote.number', 'OF-2026-001')
                ->where('can.update', true)
                ->where('can.convert', true));
        $this->actAsOrg()->get('/quotes/create')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quotes/Form')->where('suggestedNumber', 'OF-'.now()->year.'-'.(now()->year === 2026 ? '002' : '001')));
        $this->actAsOrg()->get("/quotes/{$quote->id}/edit")->assertOk();

        $pdf = $this->actAsOrg()->get("/quotes/{$quote->id}/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_a_draft_is_edited_and_a_sent_quote_is_not(): void
    {
        $quote = $this->storeQuote();

        $this->actAsOrg()->put("/quotes/{$quote->id}", [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-18',
            'lines' => [['type' => 'item', 'description' => 'Workshop', 'quantity' => 1, 'unit_price' => 500]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('500.00', $quote->refresh()->total);

        $this->actAsOrg()->post("/quotes/{$quote->id}/status", ['status' => 'sent'])->assertSessionHasNoErrors();
        $this->assertSame(QuoteStatus::Sent, $quote->refresh()->status);
        $this->assertNotNull($quote->sent_at);

        $this->actAsOrg()->put("/quotes/{$quote->id}", [
            'issue_date' => '2026-10-18',
            'lines' => [['type' => 'item', 'description' => 'Other', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertForbidden();
        $this->assertSame('500.00', $quote->refresh()->total);
    }

    public function test_an_accepted_quote_becomes_an_invoice_draft_once(): void
    {
        $quote = $this->storeQuote();
        $this->actAsOrg()->post("/quotes/{$quote->id}/status", ['status' => 'sent']);

        $response = $this->actAsOrg()->post("/quotes/{$quote->id}/convert");
        $response->assertSessionMissing('error');

        $invoice = Invoice::firstOrFail();
        $response->assertRedirect("/invoices/{$invoice->id}");
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($this->customer->id, $invoice->customer_id);
        $this->assertSame('1945.80', $invoice->total);
        $this->assertCount(3, $invoice->lines);
        $this->assertSame(now()->addDays(30)->toDateString(), $invoice->due_date->toDateString());

        $quote->refresh();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertSame($invoice->id, $quote->invoice_id);

        $this->actAsOrg()->post("/quotes/{$quote->id}/convert")->assertForbidden();
        $this->actAsOrg()->post("/quotes/{$quote->id}/status", ['status' => 'sent'])->assertForbidden();
        $this->assertSame(1, Invoice::count());
    }

    public function test_a_draft_is_not_converted_and_needs_a_customer_to_be_sent(): void
    {
        $quote = $this->storeQuote(['customer_id' => null]);

        $this->actAsOrg()->post("/quotes/{$quote->id}/convert")->assertSessionHas('error');
        $this->actAsOrg()->post("/quotes/{$quote->id}/status", ['status' => 'sent'])->assertSessionHas('error');
        $this->assertSame(QuoteStatus::Draft, $quote->refresh()->status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_draft_is_deleted_but_an_accepted_quote_is_not(): void
    {
        $draft = $this->storeQuote();
        $this->actAsOrg()->delete("/quotes/{$draft->id}")->assertRedirect('/quotes');
        $this->assertSoftDeleted($draft);

        $accepted = $this->storeQuote();
        $this->actAsOrg()->post("/quotes/{$accepted->id}/status", ['status' => 'accepted']);
        $this->actAsOrg()->delete("/quotes/{$accepted->id}")->assertForbidden();
        $this->assertNotSoftDeleted($accepted);
        $this->assertSame('OF-2026-003', $this->storeQuote()->number);
    }

    public function test_a_viewer_reads_quotes_but_does_not_write_them(): void
    {
        $quote = $this->storeQuote();
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->org, 'viewer');
        $as = fn () => $this->actingAs($viewer)->withSession(['current_organization_id' => $this->org->id]);

        $as()->get("/quotes/{$quote->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', false)->where('can.convert', false));
        $as()->post('/quotes', ['issue_date' => '2026-10-18', 'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1]]])->assertForbidden();
        $as()->post("/quotes/{$quote->id}/status", ['status' => 'sent'])->assertForbidden();
    }
}
