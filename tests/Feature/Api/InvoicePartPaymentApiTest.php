<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Enums\BankTransactionType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/**
 * Writing off the rest of an invoice and removing a payment work over the
 * API as on the invoice page.
 */
class InvoicePartPaymentApiTest extends SecurityTestCase
{
    private string $token;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        VatRate::create(['organization_id' => $this->orgA->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        foreach (['1020' => AccountType::Asset, '1100' => AccountType::Asset, '2200' => AccountType::Liability, '3000' => AccountType::Revenue, '3900' => AccountType::Revenue] as $code => $type) {
            Account::create(['organization_id' => $this->orgA->id, 'code' => $code, 'name' => "Konto {$code}", 'type' => $type->value]);
        }
        $this->customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Muster AG']);
        $this->token = $this->createApiToken($this->ownerA, $this->orgA);
    }

    /** A sent invoice over 1621.50 (1500.00 plus 8.1 % VAT). */
    private function sentInvoice(): string
    {
        $id = $this->withToken($this->token)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(25)->toDateString(),
            'lines' => [['description' => 'Beratung', 'quantity' => 10, 'unit_price' => 150, 'vat_rate_code' => 'NORMAL']],
        ], ['Idempotency-Key' => 'draft-'.uniqid()])->assertCreated()->json('data.id');
        $this->withToken($this->token)->postJson("/api/v1/invoices/{$id}/finalize")->assertOk();

        return $id;
    }

    private function pay(string $id, string $amount): void
    {
        $this->withToken($this->token)->postJson("/api/v1/invoices/{$id}/record-payment", [
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank',
        ], ['Idempotency-Key' => 'pay-'.uniqid()])->assertOk();
    }

    public function test_the_rest_is_written_off_taken_back_and_written_off_again(): void
    {
        $id = $this->sentInvoice();
        $this->pay($id, '1600.00');
        $body = ['date' => now()->toDateString(), 'reason' => 'Skonto'];

        $payments = $this->withToken($this->token)->postJson("/api/v1/invoices/{$id}/write-off", $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_due', '0.00')
            ->json('data.payments');
        $writeOff = collect($payments)->firstWhere('payment_method', 'write_off');
        $this->assertSame('21.50', $writeOff['amount']);
        $this->assertSame('Skonto', $writeOff['reference']);

        // A plain retry meets a settled invoice, as a payment would: refused,
        // nothing is booked twice.
        $this->withToken($this->token)->postJson("/api/v1/invoices/{$id}/write-off", $body)->assertForbidden();
        $this->assertSame(2, InvoicePayment::where('invoice_id', $id)->count());

        $this->withToken($this->token)->deleteJson("/api/v1/invoices/{$id}/payments/{$writeOff['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.amount_due', '21.50');

        // The same write-off once more is a new one, not a replay.
        $this->withToken($this->token)->postJson("/api/v1/invoices/{$id}/write-off", $body)
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(2, InvoicePayment::where('invoice_id', $id)->count());
    }

    public function test_two_payments_are_removed_one_after_the_other(): void
    {
        $id = $this->sentInvoice();
        $this->pay($id, '500.00');
        $this->pay($id, '400.00');
        $ids = InvoicePayment::where('invoice_id', $id)->pluck('id');

        foreach ($ids as $paymentId) {
            $this->withToken($this->token)->deleteJson("/api/v1/invoices/{$id}/payments/{$paymentId}")->assertOk();
        }

        $this->assertSame(0, InvoicePayment::where('invoice_id', $id)->count());
        $this->withToken($this->token)->getJson("/api/v1/invoices/{$id}")
            ->assertJsonPath('data.amount_due', '1621.50');
    }

    public function test_a_payment_from_the_bank_reconciliation_is_refused(): void
    {
        $id = $this->sentInvoice();
        $this->pay($id, '500.00');
        $payment = InvoicePayment::where('invoice_id', $id)->sole();
        $bank = BankAccount::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Bank',
            'currency' => 'CHF',
            'is_active' => true,
            'account_id' => Account::where('organization_id', $this->orgA->id)->where('code', '1020')->value('id'),
        ]);
        BankTransaction::create([
            'bank_account_id' => $bank->id,
            'date' => now()->toDateString(),
            'description' => 'Zahlungseingang',
            'amount' => '500.00',
            'type' => BankTransactionType::Credit->value,
            'import_hash' => 'api-'.$payment->id,
            'journal_entry_id' => $payment->journal_entry_id,
            'matched_invoice_id' => $id,
            'is_reconciled' => true,
        ]);

        $this->withToken($this->token)->deleteJson("/api/v1/invoices/{$id}/payments/{$payment->id}")
            ->assertStatus(422)->assertJsonPath('code', 'payment_from_bank');
        $this->assertModelExists($payment);
    }

    public function test_a_write_off_is_no_payment_method_and_needs_a_reason(): void
    {
        $id = $this->sentInvoice();

        $this->withToken($this->token)->postJson("/api/v1/invoices/{$id}/record-payment", [
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'write_off',
        ], ['Idempotency-Key' => 'wo-method'])->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->withToken($this->token)->postJson("/api/v1/invoices/{$id}/write-off", ['date' => now()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_another_organization_cannot_touch_the_payments(): void
    {
        $id = $this->sentInvoice();
        $this->pay($id, '500.00');
        $payment = InvoicePayment::where('invoice_id', $id)->sole();
        $tokenB = $this->createApiToken($this->ownerB, $this->orgB);

        $this->withToken($tokenB)->deleteJson("/api/v1/invoices/{$id}/payments/{$payment->id}")->assertNotFound();
        $this->withToken($tokenB)->postJson("/api/v1/invoices/{$id}/write-off", ['date' => now()->toDateString(), 'reason' => 'x'])->assertNotFound();
        $this->assertModelExists($payment);
    }
}
