<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateCreditNoteAction;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\Actions\RevertInvoiceToDraftAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Audit 2026-10-21: the VAT report against the ledger for invoices that are
 * credited or taken back. By hand: an invoice of 1500 + 121.50 VAT that is
 * fully credited or reverted owes nothing.
 */
class VatReportAuditTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $vatRate;

    private Account $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Account::create(['organization_id' => $this->org->id, 'code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $this->output = Account::create(['organization_id' => $this->org->id, 'code' => '2200', 'name' => 'VAT Output', 'type' => AccountType::Liability->value]);
        $this->vatRate = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Test Client AG']);
    }

    public function test_a_credit_note_takes_its_vat_off_the_report(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->invoice());
        $creditNote = app(CreateCreditNoteAction::class)->execute($invoice);
        app(FinalizeInvoiceAction::class)->execute($creditNote);

        $report = app(VatReportService::class)->generate($this->org->id, '2026-01-01', '2026-12-31');

        $this->assertSame('0.00', app(LedgerQueryService::class)->accountBalance($this->output->id, null, '2026-12-31'), 'The ledger owes nothing.');
        $this->assertSame('0.00', $report['total_output_vat'], 'The report should owe nothing either.');
    }

    public function test_a_reverted_invoice_takes_its_vat_off_the_report(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->invoice());
        app(RevertInvoiceToDraftAction::class)->execute($invoice);

        $report = app(VatReportService::class)->generate($this->org->id, '2026-01-01', '2026-12-31');

        $this->assertSame('0.00', app(LedgerQueryService::class)->accountBalance($this->output->id, null, '2026-12-31'), 'The ledger owes nothing.');
        $this->assertSame('0.00', $report['total_output_vat'], 'The report should owe nothing either.');
    }

    private function invoice(): Invoice
    {
        return app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->org->id,
            customerId: $this->customer->id,
            number: 'INV-2026-001',
            issueDate: '2026-03-16',
            dueDate: '2026-04-15',
            currency: 'CHF',
            notes: null,
            paymentTerms: null,
            lines: [InvoiceLineData::fromArray([
                'description' => 'Web Development',
                'quantity' => 10,
                'unit_price' => 150.00,
                'vat_rate_id' => $this->vatRate->id,
            ])],
        ));
    }
}
