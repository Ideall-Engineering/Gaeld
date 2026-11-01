<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Mail\InvoiceReminderMail;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class PaymentReminderTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    public function test_the_reminder_names_the_overdue_rates(): void
    {
        $this->setUpOrganization();
        $this->travelTo('2026-12-05 10:00:00');
        $customer = Contact::factory()->for($this->organization)->create(['email' => 'billing@plan.test']);
        $invoice = Invoice::factory()
            ->for($this->organization)
            ->for($customer, 'customer')
            ->overdue()
            ->create([
                'number' => 'INV-PLAN-REMIND',
                'issue_date' => '2026-11-02',
                'due_date' => '2026-11-30',
                'total' => '1621.50',
            ]);
        foreach ([['2026-11-30', '600.00'], ['2026-12-31', '600.00'], ['2027-01-31', '421.50']] as $index => [$date, $amount]) {
            $invoice->installments()->create(['organization_id' => $this->organization->id, 'position' => $index + 1, 'due_date' => $date, 'amount' => $amount]);
        }

        $mail = new InvoiceReminderMail($invoice->fresh(), $this->organization, 1);
        $html = $mail->render();

        $this->assertSame('600.00', $mail->amountDue);
        $this->assertCount(1, $mail->overdueRates);
        $this->assertStringContainsString(__('mail.reminder_rate_line', ['position' => 1, 'count' => 3, 'due_date' => '30.11.2026', 'currency' => 'CHF', 'amount' => '600.00']), $html);
        $this->assertStringNotContainsString("1'621.50", $html);
    }

    public function test_marking_an_invoice_overdue_does_not_send_a_payment_reminder(): void
    {
        $this->setUpOrganization();
        Mail::fake();

        $customer = Contact::factory()
            ->for($this->organization)
            ->create(['email' => 'billing@reminder.test']);
        $invoice = Invoice::factory()
            ->for($this->organization)
            ->for($customer, 'customer')
            ->sent()
            ->create([
                'number' => 'INV-REMINDER-001',
                'issue_date' => now()->subDays(30)->toDateString(),
                'due_date' => now()->subDays(10)->toDateString(),
                'total' => '1250.00',
            ]);

        $archivedCustomer = Contact::factory()
            ->for($this->organization)
            ->create(['email' => 'billing@archived-reminder.test']);
        $archivedInvoice = Invoice::factory()
            ->for($this->organization)
            ->for($archivedCustomer, 'customer')
            ->overdue()
            ->create([
                'number' => 'INV-REMINDER-ARCHIVED',
                'archived_at' => now(),
            ]);

        $this->artisan('invoices:mark-overdue')
            ->assertExitCode(0);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Overdue, $invoice->status);
        $this->assertTrue($invoice->isOverdue());
        $this->assertCount(2, Invoice::overdue()->get());

        $invoice->refresh();
        $this->assertSame(0, $invoice->reminder_count);
        $this->assertNull($invoice->last_reminded_at);
        Mail::assertNothingSent();

        $this->assertSame(InvoiceStatus::Overdue, $invoice->status);
        $this->assertSame(0, $archivedInvoice->fresh()->reminder_count);
    }
}
