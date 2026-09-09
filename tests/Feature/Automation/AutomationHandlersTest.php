<?php

namespace Tests\Feature\Automation;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\Handlers\MissingDocumentsHandler;
use App\Domains\Automation\Handlers\OverdueInvoicesHandler;
use App\Domains\Automation\Handlers\QrPaymentMatchingHandler;
use App\Domains\Automation\Handlers\TaxDeclarationReadinessHandler;
use App\Domains\Automation\Handlers\UnclearPaymentsHandler;
use App\Domains\Banking\Enums\BankTransactionType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Jobs\SendPaymentRemindersJob;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceMailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class AutomationHandlersTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Config::set('features.automation', true);

        foreach ([
            ['1020', AccountType::Asset],
            ['1100', AccountType::Asset],
            ['3000', AccountType::Revenue],
            ['6530', AccountType::Expense],
        ] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->org->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type->value,
            ]);
        }

        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'CHF Konto',
            'iban' => 'CH56 0483 5012 3456 7800 9',
            'currency' => 'CHF',
            'is_active' => true,
            // Reconciliation posts to the ledger, so the bank account has to
            // point at its general ledger account the way a real one does.
            'account_id' => Account::where('organization_id', $this->org->id)
                ->where('code', '1020')->value('id'),
        ]);
    }

    private function context(array $payload = []): AutomationContext
    {
        return new AutomationContext($this->org, 'test:1', $payload);
    }

    private function customer(): Contact
    {
        return Contact::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'kunde@example.test',
        ]);
    }

    private function invoice(string $reference, string $total): Invoice
    {
        return Invoice::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer()->id,
            'qr_reference' => $reference,
            'total' => $total,
            'status' => InvoiceStatus::Sent,
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-31',
        ]);
    }

    private function incoming(string $reference, string $amount, string $date = '2026-02-05'): BankTransaction
    {
        return BankTransaction::create([
            'bank_account_id' => $this->bankAccount->id,
            'date' => $date,
            'description' => 'Zahlungseingang',
            'amount' => $amount,
            'type' => BankTransactionType::Credit->value,
            'structured_reference' => $reference,
            'import_hash' => 'test-'.$reference.'-'.$amount,
            'is_reconciled' => false,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  QR payment matching — the only handler that touches the ledger
    // ──────────────────────────────────────────────────────────────

    public function test_an_exact_qr_reference_and_amount_is_matched(): void
    {
        $reference = '210000000003139471430009017';
        $invoice = $this->invoice($reference, '1200.00');
        $transaction = $this->incoming($reference, '1200.00');

        $result = app(QrPaymentMatchingHandler::class)->run($this->context());

        $this->assertSame(1, $result->summary['matched']);
        $this->assertTrue($transaction->fresh()->is_reconciled);
        $this->assertFalse($result->hasFindings());
    }

    public function test_a_short_payment_is_left_for_a_person(): void
    {
        $reference = '210000000003139471430009017';
        $this->invoice($reference, '1200.00');
        $transaction = $this->incoming($reference, '900.00');

        $result = app(QrPaymentMatchingHandler::class)->run($this->context());

        $this->assertSame(0, $result->summary['matched']);
        $this->assertFalse($transaction->fresh()->is_reconciled);
        $this->assertTrue($result->hasFindings());
    }

    public function test_an_unknown_reference_is_ignored_quietly(): void
    {
        $transaction = $this->incoming('999999999999999999999999999', '500.00');

        $result = app(QrPaymentMatchingHandler::class)->run($this->context());

        $this->assertSame(0, $result->summary['matched']);
        $this->assertFalse($transaction->fresh()->is_reconciled);
        $this->assertFalse($result->hasFindings());
    }

    public function test_running_it_twice_matches_nothing_the_second_time(): void
    {
        $reference = '210000000003139471430009017';
        $this->invoice($reference, '1200.00');
        $this->incoming($reference, '1200.00');

        $handler = app(QrPaymentMatchingHandler::class);
        $handler->run($this->context());
        $second = $handler->run($this->context());

        $this->assertSame(0, $second->summary['matched']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Reporting handlers
    // ──────────────────────────────────────────────────────────────

    public function test_stale_unmatched_payments_are_listed(): void
    {
        $this->incoming('111', '50.00', now()->subDays(30)->toDateString());
        $this->incoming('222', '60.00', now()->toDateString());   // too new to be stuck

        $result = app(UnclearPaymentsHandler::class)->run($this->context());

        $this->assertSame(1, $result->summary['unclear']);
    }

    public function test_overdue_invoices_are_reported_without_sending_anything(): void
    {
        $this->invoice('333', '400.00');   // due 2026-01-31, long past

        $result = app(OverdueInvoicesHandler::class)->run($this->context());

        $this->assertSame(1, $result->summary['overdue']);
        $this->assertTrue($result->hasFindings());
    }

    public function test_expenses_without_a_receipt_are_reported(): void
    {
        Expense::factory()->create([
            'organization_id' => $this->org->id,
            'receipt_path' => null,
            'vat_rate_id' => null,
            'tax_treatment' => ExpenseTaxTreatment::Standard->value,
            'date' => '2026-01-10',
        ]);

        $result = app(MissingDocumentsHandler::class)->run($this->context());

        $this->assertSame(1, $result->summary['without_receipt']);
        $this->assertSame(1, $result->summary['without_vat_rate']);
    }

    public function test_an_import_awaiting_its_customs_assessment_is_reported(): void
    {
        Expense::factory()->create([
            'organization_id' => $this->org->id,
            'receipt_path' => null,
            'tax_treatment' => ExpenseTaxTreatment::ImportTax->value,
            'date' => '2026-01-10',
        ]);

        $result = app(MissingDocumentsHandler::class)->run($this->context());

        $this->assertSame(1, $result->summary['awaiting_customs_document']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Readiness check
    // ──────────────────────────────────────────────────────────────

    public function test_the_readiness_check_names_what_is_still_open(): void
    {
        FiscalYear::create([
            'organization_id' => $this->org->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'operative',
        ]);

        $this->incoming('444', '75.00', '2026-03-01');

        $result = app(TaxDeclarationReadinessHandler::class)->run($this->context(['fiscal_year' => '2026']));

        $this->assertSame(1, $result->summary['unreconciled_transactions']);
        $this->assertFalse($result->summary['ready']);
        $this->assertTrue($result->hasFindings());
    }

    public function test_a_settled_year_reports_ready(): void
    {
        FiscalYear::create([
            'organization_id' => $this->org->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'operative',
        ]);

        $result = app(TaxDeclarationReadinessHandler::class)->run($this->context(['fiscal_year' => '2026']));

        $this->assertTrue($result->summary['ready']);
        $this->assertFalse($result->hasFindings());
    }

    // ──────────────────────────────────────────────────────────────
    //  The reminder behaviour change
    // ──────────────────────────────────────────────────────────────

    public function test_the_nightly_reminder_job_stands_down_while_automation_is_on(): void
    {
        $this->invoice('555', '400.00');

        $mailer = Mockery::mock(InvoiceMailerService::class);
        $mailer->shouldNotReceive('sendReminder');

        (new SendPaymentRemindersJob)->handle($mailer);

        $this->assertTrue(true);
    }

    public function test_the_nightly_reminder_job_still_works_without_the_automation_module(): void
    {
        Config::set('features.automation', false);
        $this->invoice('666', '400.00');

        $mailer = Mockery::mock(InvoiceMailerService::class);
        $mailer->shouldReceive('sendReminder')->once();

        (new SendPaymentRemindersJob)->handle($mailer);

        $this->assertTrue(true);
    }
}
