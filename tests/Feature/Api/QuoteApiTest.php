<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Testing\TestResponse;
use Tests\Security\SecurityTestCase;

/**
 * Quotes through the API: the whole life of a quote, from draft to invoice.
 */
class QuoteApiTest extends SecurityTestCase
{
    private string $tokenA;

    private Contact $customer;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);

        $this->vatRate = VatRate::create(['organization_id' => $this->orgA->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Schule Zug']);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
    }

    /** @param array<string, mixed> $overrides */
    private function createQuote(array $overrides = []): TestResponse
    {
        return $this->withToken($this->tokenA)->postJson('/api/v1/quotes', [
            'customer_id' => $this->customer->uuid,
            'title' => 'Workshop KI',
            'issue_date' => '2026-10-18',
            'valid_until' => '2026-11-17',
            'payment_terms' => '30',
            'lines' => [
                ['description' => 'Workshop, halber Tag', 'quantity' => 2, 'unit_price' => 1000, 'vat_rate_id' => $this->vatRate->uuid],
                ['type' => 'text', 'description' => 'Material inbegriffen'],
            ],
            ...$overrides,
        ]);
    }

    public function test_a_quote_goes_from_draft_to_invoice(): void
    {
        $created = $this->createQuote()
            ->assertCreated()
            ->assertJsonPath('data.number', 'OF-2026-001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total', '2162.00')
            ->assertJsonPath('data.customer.id', $this->customer->uuid)
            ->assertJsonPath('data.lines.0.vat_rate_id', $this->vatRate->uuid)
            ->assertJsonPath('data.lines.1.type', 'text');
        $id = $created->json('data.id');

        $this->withToken($this->tokenA)->patchJson("/api/v1/quotes/{$id}", ['title' => 'Workshop KI, Variante B'], ['Idempotency-Key' => 'patch-1'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Workshop KI, Variante B')
            ->assertJsonPath('data.total', '2162.00');

        $this->withToken($this->tokenA)->getJson('/api/v1/quotes?filter[status]=draft')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->tokenA)->postJson("/api/v1/quotes/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->withToken($this->tokenA)->patchJson("/api/v1/quotes/{$id}", ['title' => 'x'], ['Idempotency-Key' => 'patch-2'])->assertForbidden();
        $this->withToken($this->tokenA)->postJson("/api/v1/quotes/{$id}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');

        $invoice = $this->withToken($this->tokenA)->postJson("/api/v1/quotes/{$id}/convert")
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total', '2162.00');

        $this->withToken($this->tokenA)->getJson("/api/v1/quotes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_id', $invoice->json('data.id'));
        // A repeated call is answered with the first invoice, not a second one.
        $this->withToken($this->tokenA)->postJson("/api/v1/quotes/{$id}/convert")
            ->assertJsonPath('data.id', $invoice->json('data.id'));
        $this->withToken($this->tokenA)->postJson("/api/v1/quotes/{$id}/convert", [], ['Idempotency-Key' => 'again'])->assertForbidden();
        $this->assertSame(1, Invoice::count());
    }

    public function test_the_pdf_is_served(): void
    {
        $id = $this->createQuote()->json('data.id');

        $response = $this->withToken($this->tokenA)->get("/api/v1/quotes/{$id}/pdf");
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_invalid_input_and_steps_are_refused(): void
    {
        $this->createQuote(['lines' => []])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->createQuote(['valid_until' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('valid_until');

        $id = $this->createQuote(['customer_id' => null])->assertCreated()->json('data.id');
        $this->withToken($this->tokenA)->postJson("/api/v1/quotes/{$id}/send")->assertUnprocessable()->assertJsonPath('code', 'invalid_quote_state');
        $this->withToken($this->tokenA)->postJson("/api/v1/quotes/{$id}/convert")->assertUnprocessable();

        $this->withToken($this->tokenA)->deleteJson("/api/v1/quotes/{$id}")->assertNoContent();
        $this->assertSoftDeleted(Quote::withTrashed()->find($id));
    }

    public function test_a_quote_of_another_organization_stays_hidden(): void
    {
        app(CurrentOrganization::class)->set($this->orgB);
        $foreign = Quote::create(['organization_id' => $this->orgB->id, 'number' => 'OF-2026-001', 'issue_date' => '2026-10-18', 'currency' => 'CHF']);
        app(CurrentOrganization::class)->set($this->orgA);

        $this->assertDenied($this->withToken($this->tokenA)->getJson("/api/v1/quotes/{$foreign->id}"));
        $this->assertDenied($this->withToken($this->tokenA)->postJson("/api/v1/quotes/{$foreign->id}/convert"));
    }
}
