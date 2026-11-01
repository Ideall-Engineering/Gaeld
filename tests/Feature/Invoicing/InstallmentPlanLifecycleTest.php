<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Actions\UnreconcileTransactionAction;
use App\Domains\Banking\Enums\BankTransactionType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Banking\Services\ReconciliationService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\DuplicateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\Actions\RemoveInvoicePaymentAction;
use App\Domains\Invoicing\Actions\WriteOffInvoiceRemainderAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\PaymentMethod;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Invoicing\Services\InstallmentPlanService;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Spec 013: finalizing a payment plan, and the due date and status that
 * follow the rates through payments from anywhere.
 */
class InstallmentPlanLifecycleTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $standard;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02 10:00:00');
        $this->setUpOrganization();

        foreach (['1020' => AccountType::Asset, '1100' => AccountType::Asset, '2200' => AccountType::Liability, '3000' => AccountType::Revenue, '3900' => AccountType::Revenue] as $code => $type) {
            Account::create(['organization_id' => $this->org->id, 'code' => (string) $code, 'name' => "Konto {$code}", 'type' => $type->value]);
        }
        $this->standard = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Normal', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Kunde AG']);
        $this->bank = BankAccount::create([
            'organization_id' => $this->org->id,
            'account_id' => Account::where('organization_id', $this->org->id)->where('code', '1020')->value('id'),
            'name' => 'QR-Konto',
            'currency' => 'CHF',
            'qr_iban' => 'CH4431999123000889012',
            'is_default_for_invoicing' => true,
            'is_active' => true,
        ]);
    }

    private function draft(): Invoice
    {
        $invoice = app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->org->id,
            customerId: $this->customer->id,
            number: 'INV-2026-042',
            issueDate: '2026-11-02',
            dueDate: null,
            currency: 'CHF',
            notes: null,
            paymentTerms: null,
            lines: [InvoiceLineData::fromArray(['description' => 'Beratung', 'quantity' => 10, 'unit_price' => 150.00, 'vat_rate_id' => $this->standard->id])],
            installments: [
                ['due_date' => '2026-11-30', 'amount' => '600.00'],
                ['due_date' => '2026-12-31', 'amount' => '600.00'],
                ['due_date' => '2027-01-31'],
            ],
        ));

        return $invoice->fresh();
    }

    private function finalized(): Invoice
    {
        return app(FinalizeInvoiceAction::class)->execute($this->draft());
    }

    private function pay(Invoice $invoice, string $amount): InvoicePayment
    {
        return app(InvoiceAccountingService::class)->recordPayment($invoice->fresh(), new RecordPaymentData(
            amount: $amount,
            paymentDate: now()->toDateString(),
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));
    }

    private function fromBank(Invoice $invoice, string $amount): BankTransaction
    {
        $transaction = BankTransaction::create([
            'bank_account_id' => $this->bank->id,
            'date' => now()->toDateString(),
            'description' => 'Zahlung Kunde AG',
            'amount' => $amount,
            'type' => BankTransactionType::Credit,
            'reference' => $invoice->number,
        ]);

        return app(ReconciliationService::class)->reconcileWithInvoice($transaction, $invoice->fresh());
    }

    public function test_finalizing_gives_each_rate_its_own_reference(): void
    {
        $invoice = $this->finalized();

        $references = $invoice->installments()->pluck('qr_reference')->all();
        $this->assertCount(3, array_unique(array_filter($references)));
        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^\d{27}$/', $reference);
            $this->assertNotSame($invoice->fresh()->qr_reference, $reference);
        }
    }

    public function test_a_plan_that_no_longer_adds_up_is_not_finalized(): void
    {
        $invoice = $this->draft();
        $invoice->installments()->where('position', 3)->update(['amount' => '400.00']);

        $this->expectException(InvalidInvoiceStateException::class);
        app(FinalizeInvoiceAction::class)->execute($invoice);
    }

    public function test_paying_the_overdue_rate_late_brings_the_invoice_back_to_sent(): void
    {
        $invoice = $this->finalized();
        $this->travelTo('2026-12-05 10:00:00');
        $invoice->update(['status' => InvoiceStatus::Overdue]);

        $this->pay($invoice, '600.00');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('2026-12-31', $invoice->due_date->toDateString());
    }

    public function test_a_bank_line_taken_back_reopens_the_rate(): void
    {
        $invoice = $this->finalized();
        $this->travelTo('2026-12-05 10:00:00');
        $reconciled = $this->fromBank($invoice, '600.00');
        $this->assertSame('2026-12-31', $invoice->fresh()->due_date->toDateString());

        app(UnreconcileTransactionAction::class)->execute($reconciled);

        $invoice->refresh();
        $this->assertSame('2026-11-30', $invoice->due_date->toDateString());
        $this->assertSame(InvoiceStatus::Overdue, $invoice->status);
    }

    public function test_a_full_bank_payment_taken_back_reopens_the_overdue_rate(): void
    {
        $invoice = $this->finalized();
        $this->travelTo('2026-12-05 10:00:00');
        $reconciled = $this->fromBank($invoice, '1621.50');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        app(UnreconcileTransactionAction::class)->execute($reconciled);

        $invoice->refresh();
        $this->assertSame('2026-11-30', $invoice->due_date->toDateString());
        $this->assertSame(InvoiceStatus::Overdue, $invoice->status);
    }

    public function test_a_removed_full_payment_reopens_the_overdue_rate(): void
    {
        $invoice = $this->finalized();
        $this->travelTo('2026-12-05 10:00:00');
        $payment = $this->pay($invoice, '1621.50');

        app(RemoveInvoicePaymentAction::class)->execute($invoice->fresh(), $payment);

        $invoice->refresh();
        $this->assertSame('2026-11-30', $invoice->due_date->toDateString());
        $this->assertSame(InvoiceStatus::Overdue, $invoice->status);
    }

    public function test_a_write_off_settles_every_rate(): void
    {
        $invoice = $this->finalized();
        $this->pay($invoice, '600.00');

        app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Kulanz');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(['0.00', '0.00', '0.00'], array_column(app(InstallmentPlanService::class)->states($invoice), 'open'));
    }

    public function test_the_duplicate_keeps_the_plan_from_its_new_date(): void
    {
        $invoice = $this->finalized();
        $this->travelTo('2027-03-01 10:00:00');

        $copy = app(DuplicateInvoiceAction::class)->execute($invoice->fresh());

        $rates = $copy->installments()->get();
        $this->assertSame(['2027-03-29', '2027-04-29', '2027-05-30'], $rates->map(fn ($rate) => $rate->due_date->toDateString())->all());
        $this->assertSame(['600.00', '600.00', '421.50'], $rates->map(fn ($rate) => (string) $rate->amount)->all());
        $this->assertSame([null, null, null], $rates->pluck('qr_reference')->all());
        $this->assertSame('2027-03-29', $copy->fresh()->due_date->toDateString());
    }
}
