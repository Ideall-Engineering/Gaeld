<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The status column of the invoice and quote lists: one group per document,
 * counts per group, and a default that hides finished work.
 */
class DocumentListStatesTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->customer = Contact::factory()->create(['organization_id' => $this->org->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(string $number, array $attributes = []): Invoice
    {
        return Invoice::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'number' => $number,
            'total' => 1000,
            ...$attributes,
        ]);
    }

    private function pay(Invoice $invoice, string $amount): void
    {
        InvoicePayment::create([
            'organization_id' => $this->org->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
        ]);
    }

    /** One invoice per group, plus a credit note that is a draft. */
    private function invoicesInEveryGroup(): void
    {
        $this->invoice('R-DRAFT');
        $this->invoice('R-SENT', ['status' => InvoiceStatus::Sent, 'due_date' => now()->addDays(10)->toDateString()]);
        $this->invoice('R-LATE', ['status' => InvoiceStatus::Sent, 'due_date' => now()->subDays(3)->toDateString()]);
        $this->invoice('R-LATE2', ['status' => InvoiceStatus::Overdue, 'due_date' => now()->subDays(40)->toDateString()]);
        $this->pay($this->invoice('R-PART', ['status' => InvoiceStatus::Overdue, 'due_date' => now()->subDays(5)->toDateString()]), '400.00');
        $this->invoice('R-PAID', ['status' => InvoiceStatus::Paid]);
        $this->invoice('R-STORNO', ['status' => InvoiceStatus::Cancelled]);
        $this->invoice('G-1', ['type' => InvoiceType::CreditNote]);
    }

    /** @return array<int, string> */
    private function numbersOn(string $url, string $prop): array
    {
        $numbers = [];
        $this->actAsOrg()->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$numbers, $prop) {
            $numbers = collect($page->toArray()['props'][$prop]['data'])->pluck('number')->sort()->values()->all();
        });

        return $numbers;
    }

    public function test_the_invoice_column_counts_every_group_once(): void
    {
        $this->invoicesInEveryGroup();

        $this->actAsOrg()->get('/invoices')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Index')
            ->where('states', fn ($states) => collect($states)->pluck('count', 'value')->all() === [
                'draft' => 1, 'sent' => 1, 'overdue' => 2, 'partial' => 1, 'paid' => 1, 'cancelled' => 1,
            ])
            ->where('query.filter.state', 'draft,sent,overdue,partial,paid')
            ->where('query.filter.type', 'invoice'));
    }

    public function test_a_first_visit_hides_cancelled_invoices_and_credit_notes(): void
    {
        $this->invoicesInEveryGroup();

        $this->assertSame(
            ['R-DRAFT', 'R-LATE', 'R-LATE2', 'R-PAID', 'R-PART', 'R-SENT'],
            $this->numbersOn('/invoices', 'invoices'),
        );
    }

    public function test_the_chosen_invoice_groups_combine(): void
    {
        $this->invoicesInEveryGroup();

        $this->assertSame(['R-LATE', 'R-LATE2', 'R-PART'], $this->numbersOn('/invoices?filter[state]=overdue,partial&filter[type]=invoice', 'invoices'));
        $this->assertSame(['R-STORNO'], $this->numbersOn('/invoices?filter[state]=cancelled&filter[type]=invoice', 'invoices'));
    }

    public function test_an_empty_choice_shows_all_invoices_of_the_type(): void
    {
        $this->invoicesInEveryGroup();

        $this->assertCount(7, $this->numbersOn('/invoices?filter[state]=&filter[type]=invoice', 'invoices'));
        $this->assertSame(['G-1'], $this->numbersOn('/invoices?filter[state]=&filter[type]=credit_note', 'invoices'));
    }

    public function test_the_counts_follow_the_chosen_type(): void
    {
        $this->invoicesInEveryGroup();

        $this->actAsOrg()->get('/invoices?filter[state]=draft&filter[type]=credit_note')->assertInertia(fn (Assert $page) => $page
            ->where('states.0.count', 1)
            ->where('states.4.count', 0));
    }

    public function test_a_dashboard_link_by_status_keeps_working(): void
    {
        $this->invoicesInEveryGroup();

        $this->assertSame(['R-PAID'], $this->numbersOn('/invoices?filter[status]=paid', 'invoices'));
    }

    public function test_another_organisation_is_not_counted(): void
    {
        $this->invoicesInEveryGroup();
        $other = Organization::factory()->create();
        Invoice::factory()->create(['organization_id' => $other->id, 'customer_id' => Contact::factory()->create(['organization_id' => $other->id])->id]);

        $this->actAsOrg()->get('/invoices')->assertInertia(fn (Assert $page) => $page->where('states.0.count', 1));
    }

    private function quote(string $number, string $status, ?Invoice $invoice = null): Quote
    {
        $vat = VatRate::firstOrCreate(['organization_id' => $this->org->id, 'code' => 'NORMAL'], ['name' => 'Standard', 'rate' => 8.10, 'is_default' => true]);
        $this->actAsOrg()->post('/quotes', [
            'customer_id' => $this->customer->id,
            'number' => $number,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [['type' => 'item', 'description' => 'Workshop', 'quantity' => 1, 'unit_price' => 900, 'vat_rate_id' => $vat->id]],
        ])->assertSessionHasNoErrors();

        $quote = Quote::latest('id')->firstOrFail();
        $quote->forceFill(['number' => $number, 'status' => $status, 'invoice_id' => $invoice?->id])->save();

        return $quote;
    }

    public function test_the_quote_column_counts_and_hides_finished_quotes(): void
    {
        $this->quote('O-DRAFT', 'draft');
        $this->quote('O-SENT', 'sent');
        $this->quote('O-YES', 'accepted');
        $this->quote('O-NO', 'declined');
        $this->quote('O-BILLED', 'accepted', $this->invoice('R-FROM-QUOTE'));

        $this->actAsOrg()->get('/quotes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Quotes/Index')
            ->where('states', fn ($states) => collect($states)->pluck('count', 'value')->all() === [
                'draft' => 1, 'sent' => 1, 'accepted' => 1, 'declined' => 1, 'invoiced' => 1,
            ])
            ->where('query.filter.state', 'draft,sent,accepted'));

        $this->assertSame(['O-DRAFT', 'O-SENT', 'O-YES'], $this->numbersOn('/quotes', 'quotes'));
        $this->assertSame(['O-BILLED', 'O-NO'], $this->numbersOn('/quotes?filter[state]=declined,invoiced', 'quotes'));
    }
}
