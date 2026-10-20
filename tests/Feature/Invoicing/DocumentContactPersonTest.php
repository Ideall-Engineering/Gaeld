<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Spec 011: a quote names a contact person of its customer, a unit per line,
 * a delivery note and an intro, and hands all of it to its invoice.
 */
class DocumentContactPersonTest extends TestCase
{
    use BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private ContactPerson $person;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->vatRate = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Muster Stiftung']);
        $this->person = $this->customer->contactPersons()->create([
            'first_name' => 'Anna', 'last_name' => 'Muster', 'department' => 'Bildung', 'is_primary' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function storeQuote(array $overrides = []): Quote
    {
        $this->actAsOrg()->post('/quotes', [
            'customer_id' => $this->customer->id,
            'contact_person_id' => $this->person->id,
            'issue_date' => '2026-10-20',
            'delivery_note' => 'Termine nach Vereinbarung',
            'intro_text' => 'Gerne unterbreiten wir Ihnen unsere Offerte.',
            'lines' => [
                ['type' => 'item', 'description' => 'Workshop', 'quantity' => 2, 'unit' => 'Pauschal', 'unit_price' => 1200, 'vat_rate_id' => $this->vatRate->id],
            ],
            ...$overrides,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Quote::orderByDesc('number')->firstOrFail();
    }

    public function test_the_quote_keeps_person_unit_and_texts_and_passes_them_to_the_invoice(): void
    {
        $quote = $this->storeQuote();

        $this->assertSame($this->person->id, $quote->contact_person_id);
        $this->assertSame('Anna Muster', data_get($quote->customer_snapshot, 'contact_person.name'));
        $this->assertSame('Bildung', data_get($quote->customer_snapshot, 'contact_person.department'));
        $this->assertSame('Pauschal', $quote->lines->first()->unit);
        $this->assertSame('Termine nach Vereinbarung', $quote->delivery_note);

        $this->actAsOrg()->post("/quotes/{$quote->id}/status", ['status' => 'sent']);
        $this->actAsOrg()->post("/quotes/{$quote->id}/convert")->assertSessionMissing('error');

        $invoice = Invoice::with('lines')->firstOrFail();
        $this->assertSame($this->person->id, $invoice->contact_person_id);
        $this->assertSame('Anna Muster', data_get($invoice->customer_snapshot, 'contact_person.name'));
        $this->assertSame('Pauschal', $invoice->lines->first()->unit);
        $this->assertSame('Termine nach Vereinbarung', $invoice->delivery_note);
        $this->assertSame('Gerne unterbreiten wir Ihnen unsere Offerte.', $invoice->intro_text);
    }

    public function test_a_person_of_another_customer_is_refused(): void
    {
        $other = Contact::create(['organization_id' => $this->org->id, 'name' => 'Andere AG']);
        $stranger = $other->contactPersons()->create(['first_name' => 'Beat', 'last_name' => 'Fremd']);

        $this->actAsOrg()->post('/quotes', [
            'customer_id' => $this->customer->id,
            'contact_person_id' => $stranger->id,
            'issue_date' => '2026-10-20',
            'lines' => [['type' => 'item', 'description' => 'Workshop', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertSessionHasErrors('contact_person_id');
        $this->assertSame(0, Quote::count());
    }

    public function test_an_invoice_names_its_contact_person_and_a_unit(): void
    {
        $this->actAsOrg()->post('/invoices', [
            'customer_id' => $this->customer->id,
            'contact_person_id' => $this->person->id,
            'issue_date' => '2026-10-20',
            'due_date' => '2026-11-19',
            'lines' => [['type' => 'item', 'description' => 'Beratung', 'quantity' => 6, 'unit' => 'Stunde', 'unit_price' => 150, 'vat_rate_id' => $this->vatRate->id]],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::with('lines')->firstOrFail();
        $this->assertSame($this->person->id, $invoice->contact_person_id);
        $this->assertSame('Stunde', $invoice->lines->first()->unit);
        $this->assertSame('972.90', $invoice->total);
    }

    public function test_a_job_is_created_from_the_form_and_comes_back_as_an_option(): void
    {
        $this->enableProjects();
        $area = $this->makeProject('B-KURSE', null, ProjectKind::Area);

        $response = $this->actAsOrg()->postJson('/projects', [
            'code' => '',
            'name' => 'Workshop Muster Stiftung',
            'parent_id' => $area->id,
            'kind' => 'project',
            'status' => 'pipeline',
            'contact_id' => $this->customer->id,
        ])->assertCreated();

        $project = Project::where('name', 'Workshop Muster Stiftung')->firstOrFail();
        $response->assertJsonPath('project.id', $project->id)
            ->assertJsonPath('project.depth', 1)
            ->assertJsonPath('project.contact_id', $this->customer->id);
        $this->assertNotSame('', $project->code);
    }
}
