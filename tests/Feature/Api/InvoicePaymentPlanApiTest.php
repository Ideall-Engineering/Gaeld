<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/**
 * Spec 013: the payment plan of an invoice is written and read over the API.
 */
class InvoicePaymentPlanApiTest extends SecurityTestCase
{
    private string $token;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02 10:00:00');

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        VatRate::create(['organization_id' => $this->orgA->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        foreach (['1020' => AccountType::Asset, '1100' => AccountType::Asset, '2200' => AccountType::Liability, '3000' => AccountType::Revenue] as $code => $type) {
            Account::create(['organization_id' => $this->orgA->id, 'code' => (string) $code, 'name' => "Konto {$code}", 'type' => $type->value]);
        }
        $this->customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Muster AG']);
        $this->token = $this->createApiToken($this->ownerA, $this->orgA);
    }

    public function test_the_api_writes_and_reads_the_plan(): void
    {
        $id = $this->withToken($this->token)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-11-02',
            'lines' => [['description' => 'Beratung', 'quantity' => 10, 'unit_price' => 150, 'vat_rate_code' => 'NORMAL']],
            'installments' => [
                ['due_date' => '2026-11-30', 'amount' => 600],
                ['due_date' => '2026-12-31', 'amount' => 600],
                ['due_date' => '2027-01-31', 'amount' => 999],
            ],
        ], ['Idempotency-Key' => 'plan-1'])
            ->assertCreated()
            ->assertJsonPath('data.due_date', '2026-11-30')
            ->assertJsonPath('data.installments.0.position', 1)
            ->assertJsonPath('data.installments.0.amount_open', '600.00')
            ->assertJsonPath('data.installments.2.amount', '421.50')
            ->assertJsonPath('data.installments.2.amount_paid', '0.00')
            ->json('data.id');

        $this->withToken($this->token)->getJson("/api/v1/invoices/{$id}")
            ->assertJsonCount(3, 'data.installments');

        $this->withToken($this->token)->patchJson("/api/v1/invoices/{$id}", [
            'installments' => [],
            'due_date' => '2026-12-02',
        ], ['Idempotency-Key' => 'plan-2'])
            ->assertOk()
            ->assertJsonPath('data.installments', [])
            ->assertJsonPath('data.due_date', '2026-12-02');
    }

    public function test_a_plan_breaking_the_rules_is_refused(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-11-02',
            'lines' => [['description' => 'Beratung', 'quantity' => 10, 'unit_price' => 150, 'vat_rate_code' => 'NORMAL']],
            'installments' => [
                ['due_date' => '2026-11-30', 'amount' => 1000],
                ['due_date' => '2026-12-31', 'amount' => 700],
                ['due_date' => '2027-01-31'],
            ],
        ], ['Idempotency-Key' => 'plan-3'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('installments');
    }
}
