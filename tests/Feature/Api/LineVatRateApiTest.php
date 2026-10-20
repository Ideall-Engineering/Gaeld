<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/**
 * API clients see the VAT rates, may name them by code, and a VAT-registered
 * organisation cannot get a line without VAT by forgetting the rate.
 */
class LineVatRateApiTest extends SecurityTestCase
{
    private string $tokenA;

    private Contact $customer;

    private VatRate $normal;

    private VatRate $exempt;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        $this->orgA->update(['vat_number' => 'CHE-123.456.789 MWST']);

        $this->normal = VatRate::create(['organization_id' => $this->orgA->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->exempt = VatRate::create(['organization_id' => $this->orgA->id, 'name' => 'Exempt', 'rate' => 0, 'code' => 'EXEMPT']);
        VatRate::create(['organization_id' => $this->orgB->id, 'name' => 'Fremd', 'rate' => 7.7, 'code' => 'OLD']);
        $this->customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Schule Zug']);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
    }

    public function test_the_vat_rates_of_the_organisation_are_listed_default_first(): void
    {
        $this->withToken($this->tokenA)->getJson('/api/v1/vat-rates')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'NORMAL')
            ->assertJsonPath('data.0.rate', '8.10')
            ->assertJsonPath('data.0.id', $this->normal->uuid)
            ->assertJsonPath('data.1.code', 'EXEMPT');
    }

    public function test_quote_and_invoice_lines_name_their_rate_by_code(): void
    {
        $this->withToken($this->tokenA)->postJson('/api/v1/quotes', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-10-20',
            'lines' => [
                ['description' => 'Workshop', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate_code' => 'normal'],
                ['description' => 'Kurs', 'quantity' => 1, 'unit_price' => 500, 'vat_rate_code' => 'EXEMPT'],
                ['type' => 'text', 'description' => 'Termine nach Vereinbarung'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.lines.0.vat_rate_id', $this->normal->uuid)
            ->assertJsonPath('data.lines.1.vat_rate_id', $this->exempt->uuid)
            ->assertJsonPath('data.total', '1581.00');

        $this->withToken($this->tokenA)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-10-20',
            'lines' => [['description' => 'Beratung', 'quantity' => 2, 'unit_price' => 150, 'vat_rate_code' => 'NORMAL']],
        ], ['Idempotency-Key' => 'inv-code'])->assertCreated()
            ->assertJsonPath('data.vat_amount', '24.30');
    }

    public function test_a_forgotten_or_unknown_rate_is_an_error(): void
    {
        $this->withToken($this->tokenA)->postJson('/api/v1/quotes', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-10-20',
            'lines' => [['description' => 'Workshop', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.vat_rate_id');

        $this->withToken($this->tokenA)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-10-20',
            'lines' => [['description' => 'Beratung', 'quantity' => 1, 'unit_price' => 150, 'vat_rate_code' => 'OLD']],
        ], ['Idempotency-Key' => 'inv-unknown'])->assertUnprocessable()->assertJsonValidationErrors('lines.0.vat_rate_code');
    }

    public function test_an_organisation_without_vat_number_needs_no_rate(): void
    {
        $this->orgA->update(['vat_number' => null]);

        $this->withToken($this->tokenA)->postJson('/api/v1/quotes', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-10-20',
            'lines' => [['description' => 'Workshop', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertCreated()->assertJsonPath('data.total', '1000.00');
    }
}
