<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The quote and invoice lists change dates and status in the row, and a
 * document addresses the customer's primary person unless told otherwise.
 */
class DocumentListInlineTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private ContactPerson $primary;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->vatRate = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Inglin Transporte AG', 'address' => 'Hauptstrasse 1', 'postal_code' => '6300', 'city' => 'Zug']);
        $this->customer->contactPersons()->create(['first_name' => 'Beat', 'last_name' => 'Zweit']);
        $this->primary = $this->customer->contactPersons()->create(['first_name' => 'Anna', 'last_name' => 'Haupt', 'is_primary' => true]);
    }

    /** @param array<string, mixed> $overrides */
    private function storeInvoice(array $overrides = []): Invoice
    {
        $this->actAsOrg()->post('/invoices', [
            'customer_id' => $this->customer->id,
            'contact_person_id' => '',
            'issue_date' => '2026-10-19',
            'due_date' => '2026-11-18',
            'lines' => [['type' => 'item', 'description' => 'Transport', 'quantity' => 1, 'unit_price' => 1770, 'vat_rate_id' => $this->vatRate->id]],
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    private function storeQuote(): Quote
    {
        $this->actAsOrg()->post('/quotes', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-20',
            'valid_until' => '2026-11-19',
            'lines' => [['type' => 'item', 'description' => 'Workshop', 'quantity' => 1, 'unit_price' => 900, 'vat_rate_id' => $this->vatRate->id]],
        ])->assertSessionHasNoErrors();

        return Quote::latest('id')->firstOrFail();
    }

    public function test_a_duplicate_addresses_the_customer_as_it_is_today(): void
    {
        $original = $this->storeInvoice();
        $this->assertNull(data_get($original->customer_snapshot, 'contact_person'));
        $this->customer->update(['address' => 'Neue Strasse 5']);

        $this->actAsOrg()->post("/invoices/{$original->id}/duplicate")->assertSessionMissing('error');

        $copy = Invoice::latest('id')->firstOrFail();
        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame($this->primary->id, $copy->contact_person_id);
        $this->assertSame('Anna Haupt', data_get($copy->customer_snapshot, 'contact_person.name'));
        $this->assertSame('Neue Strasse 5', data_get($copy->customer_snapshot, 'address'));
    }

    public function test_a_duplicate_keeps_the_person_the_original_named(): void
    {
        $second = ContactPerson::where('last_name', 'Zweit')->firstOrFail();
        $original = $this->storeInvoice(['contact_person_id' => $second->id]);

        $this->actAsOrg()->post("/invoices/{$original->id}/duplicate");

        $this->assertSame($second->id, Invoice::latest('id')->firstOrFail()->contact_person_id);
    }

    public function test_the_list_changes_the_dates_of_a_draft_invoice(): void
    {
        $invoice = $this->storeInvoice();

        $this->actAsOrg()->from('/invoices')->patch("/invoices/{$invoice->id}/dates", ['issue_date' => '2026-10-21'])
            ->assertRedirect('/invoices')->assertSessionHas('success');
        $this->actAsOrg()->from('/invoices')->patch("/invoices/{$invoice->id}/dates", ['due_date' => '2026-12-01'])
            ->assertRedirect('/invoices');

        $invoice->refresh();
        $this->assertSame('2026-10-21', $invoice->issue_date->toDateString());
        $this->assertSame('2026-12-01', $invoice->due_date->toDateString());
    }

    public function test_a_due_date_before_the_invoice_date_is_refused(): void
    {
        $invoice = $this->storeInvoice();

        $this->actAsOrg()->from('/invoices')->patch("/invoices/{$invoice->id}/dates", ['due_date' => '2026-10-01'])
            ->assertSessionHas('error');

        $this->assertSame('2026-11-18', $invoice->fresh()->due_date->toDateString());
    }

    public function test_the_dates_of_a_finalized_invoice_stay_locked(): void
    {
        $invoice = $this->storeInvoice();
        $invoice->update(['status' => InvoiceStatus::Sent]);

        $this->actAsOrg()->patch("/invoices/{$invoice->id}/dates", ['issue_date' => '2026-10-25'])->assertForbidden();

        $this->assertSame('2026-10-19', $invoice->fresh()->issue_date->toDateString());
    }

    public function test_finalizing_from_the_list_stays_on_the_list(): void
    {
        foreach ([['1100', AccountType::Asset], ['3000', AccountType::Revenue], ['2200', AccountType::Liability]] as [$code, $type]) {
            Account::create(['organization_id' => $this->org->id, 'code' => $code, 'name' => $code, 'type' => $type->value]);
        }
        $invoice = $this->storeInvoice();

        $this->actAsOrg()->from('/invoices')->post("/invoices/{$invoice->id}/finalize", ['stay' => true])
            ->assertRedirect('/invoices');

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_the_list_changes_the_dates_of_a_draft_quote_only(): void
    {
        $quote = $this->storeQuote();

        $this->actAsOrg()->from('/quotes')->patch("/quotes/{$quote->id}/dates", ['valid_until' => '2026-12-31'])
            ->assertRedirect('/quotes');
        $this->assertSame('2026-12-31', $quote->fresh()->valid_until->toDateString());

        $this->actAsOrg()->from('/quotes')->patch("/quotes/{$quote->id}/dates", ['valid_until' => '2026-10-01'])
            ->assertSessionHas('error');

        $this->actAsOrg()->post("/quotes/{$quote->id}/status", ['status' => 'sent']);
        $this->actAsOrg()->patch("/quotes/{$quote->id}/dates", ['valid_until' => '2027-01-31'])->assertForbidden();
        $this->assertSame('2026-12-31', $quote->fresh()->valid_until->toDateString());
    }

    public function test_the_lists_carry_the_customer_link(): void
    {
        $this->storeInvoice();
        $this->storeQuote();

        $this->actAsOrg()->get('/invoices')->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->where('invoices.data.0.customer.uuid', $this->customer->uuid));
        $this->actAsOrg()->get('/quotes')->assertInertia(fn (Assert $page) => $page
            ->component('Quotes/Index')
            ->where('quotes.data.0.customer.uuid', $this->customer->uuid));
    }

    public function test_a_draft_shows_the_primary_person_even_when_added_later(): void
    {
        $this->primary->update(['is_primary' => false]);
        $invoice = $this->storeInvoice();
        $this->assertNull(data_get($invoice->fresh()->customerDetailsForDocument(), 'contact_person'));

        $this->primary->update(['is_primary' => true]);
        $this->customer->update(['address' => 'Im Grindel 31']);

        $details = $invoice->fresh()->customerDetailsForDocument();
        $this->assertSame('Anna Haupt', data_get($details, 'contact_person.name'));
        $this->assertSame('Im Grindel 31', data_get($details, 'address'));
    }

    public function test_finalizing_freezes_the_address_the_draft_showed(): void
    {
        foreach ([['1100', AccountType::Asset], ['3000', AccountType::Revenue], ['2200', AccountType::Liability]] as [$code, $type]) {
            Account::create(['organization_id' => $this->org->id, 'code' => $code, 'name' => $code, 'type' => $type->value]);
        }
        $invoice = $this->storeInvoice();

        $this->actAsOrg()->post("/invoices/{$invoice->id}/finalize")->assertSessionMissing('error');
        $this->customer->update(['address' => 'Später umgezogen 9']);
        $this->primary->update(['first_name' => 'Anders']);

        $invoice->refresh();
        $this->assertSame($this->primary->id, $invoice->contact_person_id);
        $this->assertSame('Anna Haupt', data_get($invoice->customerDetailsForDocument(), 'contact_person.name'));
        $this->assertSame('Hauptstrasse 1', data_get($invoice->customerDetailsForDocument(), 'address'));
    }

    public function test_sending_a_quote_freezes_the_address_the_draft_showed(): void
    {
        $quote = $this->storeQuote();
        $this->actAsOrg()->post("/quotes/{$quote->id}/status", ['status' => 'sent'])->assertSessionMissing('error');
        $this->customer->update(['address' => 'Später umgezogen 9']);

        $quote->refresh();
        $this->assertSame($this->primary->id, $quote->contact_person_id);
        $this->assertSame('Hauptstrasse 1', data_get($quote->customerDetailsForDocument(), 'address'));
        $this->assertSame('Anna Haupt', data_get($quote->customerDetailsForDocument(), 'contact_person.name'));
    }
}
