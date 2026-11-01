<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The invoice page must expose server-side authorization for payment and email
 * actions so the UI cannot offer an action the policy would reject.
 */
class InvoiceShowActionsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Account::create([
            'organization_id' => $this->org->id,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value,
        ]);

        $this->customer = Contact::create([
            'organization_id' => $this->org->id,
            'name' => 'Show Actions AG',
            'email' => 'billing@show-actions.test',
        ]);
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'number' => 'INV-SHOW-'.fake()->unique()->numberBetween(1000, 9999),
            'issue_date' => '2026-03-16',
            'due_date' => '2026-04-15',
            'status' => InvoiceStatus::Sent,
            'currency' => 'CHF',
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total' => '100.00',
        ], $overrides));
    }

    public function test_the_invoice_page_shows_the_rates_with_their_state(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        $invoice = $this->invoice(['total' => '1621.50', 'subtotal' => '1500.00', 'vat_amount' => '121.50', 'issue_date' => '2026-11-02', 'due_date' => '2026-11-30']);
        foreach ([['2026-11-30', '600.00'], ['2026-12-01', '600.00'], ['2027-01-31', '421.50']] as $index => [$date, $amount]) {
            $invoice->installments()->create(['organization_id' => $this->org->id, 'position' => $index + 1, 'due_date' => $date, 'amount' => $amount]);
        }
        $invoice->payments()->create(['organization_id' => $this->org->id, 'amount' => '600.00', 'payment_date' => '2026-11-29', 'payment_method' => 'bank']);

        $states = $this->actAsOrg()
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->viewData('page')['props']['installmentStates'];

        $this->assertCount(3, $states);
        $this->assertSame(['position' => 1, 'due_date' => '2026-11-30', 'amount' => '600.00', 'paid' => '600.00', 'open' => '0.00', 'overdue' => false], $states[0]);
        $this->assertSame(['600.00', '421.50'], array_column(array_slice($states, 1), 'open'));
        $this->assertSame([true, false], array_column(array_slice($states, 1), 'overdue'));
    }

    public function test_issued_invoice_exposes_payment_and_send_actions(): void
    {
        $invoice = $this->invoice();

        $props = $this->actAsOrg()
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue($props['canRecordPayment']);
        $this->assertTrue($props['canSend']);
        $this->assertTrue($props['canSendReminder']);
    }

    public function test_archived_invoice_hides_payment_and_send_actions(): void
    {
        $invoice = $this->invoice(['archived_at' => now()]);

        $props = $this->actAsOrg()
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($props['canRecordPayment']);
        $this->assertFalse($props['canSend']);
        $this->assertFalse($props['canSendReminder']);
    }

    public function test_archived_invoice_rejects_payment_and_send_requests(): void
    {
        $invoice = $this->invoice(['archived_at' => now()]);

        $this->actAsOrg()
            ->post(route('invoices.payment', $invoice), [
                'amount' => '100.00',
                'payment_date' => '2026-04-01',
                'payment_method' => 'bank',
            ])
            ->assertForbidden();

        $this->actAsOrg()
            ->post(route('invoices.send', $invoice))
            ->assertForbidden();

        $this->actAsOrg()
            ->post(route('invoices.reminder', $invoice))
            ->assertForbidden();
    }

    public function test_draft_invoice_hides_payment_and_send_actions(): void
    {
        $invoice = $this->invoice(['status' => InvoiceStatus::Draft]);

        $props = $this->actAsOrg()
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($props['canRecordPayment']);
        $this->assertFalse($props['canSend']);
        $this->assertFalse($props['canSendReminder']);
    }

    public function test_invoice_without_due_date_hides_the_reminder_action_without_error(): void
    {
        $invoice = $this->invoice(['due_date' => null]);

        $props = $this->actAsOrg()
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($invoice->isOverdue());
        $this->assertFalse($props['canSendReminder']);
    }
}
