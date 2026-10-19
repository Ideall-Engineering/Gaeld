<?php

namespace Tests\Feature\Api;

use App\Domains\Api\Enums\TokenType;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/**
 * Contact persons of a contact, kept through the API like in the web
 * interface.
 */
class ContactPersonApiTest extends SecurityTestCase
{
    private string $tokenA;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
        $this->contact = Contact::withoutGlobalScopes()->create([
            'organization_id' => $this->orgA->id,
            'name' => 'Muster AG',
        ]);
    }

    public function test_persons_are_created_listed_changed_and_deleted(): void
    {
        $base = "/api/v1/contacts/{$this->contact->uuid}/persons";

        $anna = $this->withToken($this->tokenA)->postJson($base, [
            'first_name' => 'Anna',
            'last_name' => 'Muster',
            'email' => 'anna@example.test',
            'position' => 'Leitung Einkauf',
            'is_primary' => true,
        ])->assertCreated()
            ->assertJsonPath('data.full_name', 'Anna Muster')
            ->assertJsonPath('data.is_primary', true)
            ->json('data.id');

        $beat = $this->withToken($this->tokenA)->postJson($base, [
            'first_name' => 'Beat',
            'last_name' => 'Beispiel',
        ])->assertCreated()
            ->assertJsonPath('data.is_primary', false)
            ->json('data.id');

        $this->withToken($this->tokenA)->getJson($base)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $anna);

        $this->withToken($this->tokenA)->patchJson("{$base}/{$beat}", ['phone' => '+41 41 000 00 00', 'is_primary' => true])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Beat')
            ->assertJsonPath('data.phone', '+41 41 000 00 00')
            ->assertJsonPath('data.is_primary', true);
        $this->assertFalse(ContactPerson::findOrFail($anna)->is_primary);

        $this->withToken($this->tokenA)->getJson("/api/v1/contacts/{$this->contact->uuid}")
            ->assertJsonCount(2, 'data.contact_persons');

        $this->withToken($this->tokenA)->deleteJson("{$base}/{$anna}")->assertNoContent();
        $this->assertSame(1, $this->contact->contactPersons()->count());
    }

    public function test_both_names_are_needed_to_create_a_person(): void
    {
        $this->withToken($this->tokenA)->postJson("/api/v1/contacts/{$this->contact->uuid}/persons", ['first_name' => 'Anna'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('last_name');
    }

    public function test_a_person_of_another_contact_or_organization_is_not_reachable(): void
    {
        $other = Contact::withoutGlobalScopes()->create(['organization_id' => $this->orgA->id, 'name' => 'Andere AG']);
        $person = $other->contactPersons()->create(['first_name' => 'Carla', 'last_name' => 'Fremd']);
        $foreign = Contact::withoutGlobalScopes()->create(['organization_id' => $this->orgB->id, 'name' => 'Org B AG']);

        $this->assertDenied($this->withToken($this->tokenA)->patchJson(
            "/api/v1/contacts/{$this->contact->uuid}/persons/{$person->id}",
            ['last_name' => 'Geändert'],
        ));
        $this->assertDenied($this->withToken($this->tokenA)->postJson(
            "/api/v1/contacts/{$foreign->uuid}/persons",
            ['first_name' => 'X', 'last_name' => 'Y'],
        ));
        $this->withToken($this->tokenA)->postJson("/api/v1/contacts/{$this->contact->uuid}/persons", ['first_name' => 'Carla', 'last_name' => 'Fremd'])
            ->assertCreated();
        $this->assertSame(1, $this->contact->contactPersons()->count());
        $this->assertSame(1, $other->contactPersons()->count());
        $this->assertSame('Fremd', $person->fresh()->last_name);
        $this->assertSame(0, $foreign->contactPersons()->count());
    }

    public function test_a_read_only_token_cannot_add_a_person(): void
    {
        $token = $this->ownerA->createToken('contact-read-only', ['contacts.view']);
        $token->accessToken->update(['organization_id' => $this->orgA->id, 'type' => TokenType::Personal]);
        $base = "/api/v1/contacts/{$this->contact->uuid}/persons";

        $this->withToken($token->plainTextToken)->getJson($base)->assertOk();
        $this->withToken($token->plainTextToken)->postJson($base, ['first_name' => 'X', 'last_name' => 'Y'])
            ->assertForbidden();
    }
}
