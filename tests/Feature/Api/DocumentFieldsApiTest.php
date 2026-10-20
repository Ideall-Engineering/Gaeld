<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/**
 * Spec 011 through the API: contact person, unit, delivery note and intro
 * on quotes and invoices, kept by a partial update.
 */
class DocumentFieldsApiTest extends SecurityTestCase
{
    private string $tokenA;

    private Contact $customer;

    private ContactPerson $person;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        VatRate::create(['organization_id' => $this->orgA->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Muster Stiftung']);
        $this->person = $this->customer->contactPersons()->create(['first_name' => 'Anna', 'last_name' => 'Muster']);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
    }

    public function test_a_quote_keeps_its_new_fields_through_a_partial_update(): void
    {
        $id = $this->withToken($this->tokenA)->postJson('/api/v1/quotes', [
            'customer_id' => $this->customer->uuid,
            'contact_person_id' => $this->person->id,
            'issue_date' => '2026-10-20',
            'delivery_note' => 'Termine nach Vereinbarung',
            'intro_text' => 'Gerne offerieren wir.',
            'lines' => [['description' => 'Workshop', 'quantity' => 2, 'unit' => 'Pauschal', 'unit_price' => 1200, 'vat_rate_code' => 'NORMAL']],
        ])->assertCreated()
            ->assertJsonPath('data.contact_person_id', $this->person->id)
            ->assertJsonPath('data.lines.0.unit', 'Pauschal')
            ->json('data.id');

        $this->withToken($this->tokenA)->patchJson("/api/v1/quotes/{$id}", ['title' => 'Pilot'], ['Idempotency-Key' => 'q-fields'])
            ->assertOk()
            ->assertJsonPath('data.contact_person_id', $this->person->id)
            ->assertJsonPath('data.delivery_note', 'Termine nach Vereinbarung')
            ->assertJsonPath('data.intro_text', 'Gerne offerieren wir.')
            ->assertJsonPath('data.lines.0.unit', 'Pauschal');
    }

    public function test_an_invoice_takes_a_contact_person_only_of_its_customer(): void
    {
        $other = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Andere AG']);
        $stranger = $other->contactPersons()->create(['first_name' => 'Beat', 'last_name' => 'Fremd']);
        $line = ['description' => 'Beratung', 'quantity' => 1, 'unit' => 'Stunde', 'unit_price' => 150, 'vat_rate_code' => 'NORMAL'];

        $this->withToken($this->tokenA)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid, 'contact_person_id' => $stranger->id, 'issue_date' => '2026-10-20', 'lines' => [$line],
        ], ['Idempotency-Key' => 'inv-stranger'])->assertUnprocessable()->assertJsonValidationErrors('contact_person_id');

        $this->withToken($this->tokenA)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid, 'contact_person_id' => $this->person->id, 'issue_date' => '2026-10-20', 'lines' => [$line],
        ], ['Idempotency-Key' => 'inv-person'])->assertCreated()
            ->assertJsonPath('data.contact_person_id', $this->person->id)
            ->assertJsonPath('data.lines.0.unit', 'Stunde');
    }

    public function test_a_document_without_a_named_person_addresses_the_customers_only_person(): void
    {
        $line = ['description' => 'Beratung', 'quantity' => 1, 'unit_price' => 150, 'vat_rate_code' => 'NORMAL'];

        $this->withToken($this->tokenA)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid, 'issue_date' => '2026-10-20', 'lines' => [$line],
        ], ['Idempotency-Key' => 'inv-default-person'])->assertCreated()
            ->assertJsonPath('data.contact_person_id', $this->person->id);

        $this->withToken($this->tokenA)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid, 'contact_person_id' => null, 'issue_date' => '2026-10-20', 'lines' => [$line],
        ], ['Idempotency-Key' => 'inv-no-person'])->assertCreated()
            ->assertJsonPath('data.contact_person_id', null);

        $this->withToken($this->tokenA)->postJson('/api/v1/quotes', [
            'customer_id' => $this->customer->uuid, 'issue_date' => '2026-10-20', 'lines' => [$line],
        ])->assertCreated()
            ->assertJsonPath('data.contact_person_id', $this->person->id);
    }
}
