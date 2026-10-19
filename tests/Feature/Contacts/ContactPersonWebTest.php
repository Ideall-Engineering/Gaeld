<?php

namespace Tests\Feature\Contacts;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The contact page adds, changes and removes contact persons, addressing the
 * contact by its public uuid.
 */
class ContactPersonWebTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_a_person_is_added_changed_and_removed_on_the_contact_page(): void
    {
        $contact = Contact::create(['organization_id' => $this->organization->id, 'name' => 'SAH Muster']);
        $base = "/contacts/{$contact->uuid}/contact-persons";

        $id = $this->actAsOrg()->postJson($base, ['first_name' => 'Anna', 'last_name' => 'Muster', 'is_primary' => true])
            ->assertCreated()
            ->assertJsonPath('contact_person.first_name', 'Anna')
            ->json('contact_person.id');

        $this->actAsOrg()->putJson("{$base}/{$id}", ['first_name' => 'Anna', 'last_name' => 'Beispiel'])
            ->assertOk()
            ->assertJsonPath('contact_person.last_name', 'Beispiel');

        $this->actAsOrg()->deleteJson("{$base}/{$id}")->assertNoContent();
        $this->assertSame(0, $contact->contactPersons()->count());
    }

    public function test_a_contact_of_another_organization_is_not_found(): void
    {
        $other = Organization::factory()->create();
        $foreign = Contact::withoutGlobalScopes()->create(['organization_id' => $other->id, 'name' => 'Fremd AG']);

        $this->actAsOrg()->postJson("/contacts/{$foreign->uuid}/contact-persons", ['first_name' => 'X', 'last_name' => 'Y'])
            ->assertNotFound();
        $this->actAsOrg()->postJson('/contacts/123/contact-persons', ['first_name' => 'X', 'last_name' => 'Y'])
            ->assertNotFound();
        $this->assertSame(0, $foreign->contactPersons()->count());
    }
}
