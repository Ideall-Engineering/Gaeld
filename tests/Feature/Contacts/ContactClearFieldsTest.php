<?php

namespace Tests\Feature\Contacts;

use App\Domains\Contacts\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Emptying a field on the contact form clears it; fields the form does not
 * send keep their value.
 */
class ContactClearFieldsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_an_emptied_field_is_cleared(): void
    {
        $contact = Contact::factory()->for($this->organization)->create([
            'name' => 'Muster GmbH',
            'vat_number' => 'DE123456789',
            'email' => 'info@example.test',
            'iban' => 'CH9300762011623852957',
        ]);

        $this->actAsOrg()->put("/contacts/{$contact->uuid}", [
            'name' => 'Muster GmbH',
            'vat_number' => '',
            'iban' => '',
        ])->assertRedirect("/contacts/{$contact->uuid}");

        $contact->refresh();
        $this->assertNull($contact->vat_number);
        $this->assertNull($contact->iban);
        $this->assertSame('info@example.test', $contact->email);
    }
}
