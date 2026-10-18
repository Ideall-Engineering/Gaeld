<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CancelInvoiceAction;
use App\Domains\Invoicing\Actions\CreateCreditNoteAction;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\Actions\UpdatePaymentDateAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Enums\PaymentMethod;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\InvoiceProjectService;
use App\Domains\Projects\Services\LineAllocationService;
use App\Domains\Projects\Services\ProjectOverviewService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Projects on invoices, the project overview and catching up on unallocated
 * lines (spec 009, stage 2, User Stories 7 to 9).
 */
class InvoiceProjectTest extends TestCase
{
    use BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $vatRate;

    private Account $revenueAccount;

    private Account $expenseAccount;

    private Account $bankAccount;

    private Project $area;

    private Project $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->enableProjects();

        Account::create(['organization_id' => $this->org->id, 'code' => '1100', 'name' => 'Receivables', 'type' => AccountType::Asset->value]);
        $this->revenueAccount = Account::create(['organization_id' => $this->org->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        $this->bankAccount = Account::create(['organization_id' => $this->org->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '2200', 'name' => 'VAT Output', 'type' => AccountType::Liability->value]);
        $this->expenseAccount = Account::create(['organization_id' => $this->org->id, 'code' => '4400', 'name' => 'Third-party costs', 'type' => AccountType::Expense->value]);
        $this->vatRate = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Customer AG']);

        $this->area = $this->makeProject('AREA', kind: ProjectKind::Area);
        $this->website = $this->makeProject('WEB', $this->area);
        $this->website->update(['contact_id' => $this->customer->id, 'status' => ProjectStatus::InProgress]);
    }

    public function test_booking_an_invoice_allocates_only_its_revenue_lines(): void
    {
        $invoice = $this->createInvoice();
        app(InvoiceProjectService::class)->assign($invoice, $this->website->id);

        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);

        $allocations = LineAllocation::query()->with('line.account')->get();
        $this->assertCount(1, $allocations);
        $this->assertSame('3000', $allocations->first()->line->account->code);
        $this->assertSame($this->website->id, $allocations->first()->cost_center_id);
        $this->assertSame(AllocationSource::Invoice, $allocations->first()->source);
    }

    public function test_the_project_of_a_booked_and_archived_invoice_can_change(): void
    {
        $other = $this->makeProject('OTHER');
        $invoice = $this->createInvoice();
        app(InvoiceProjectService::class)->assign($invoice, $this->website->id);
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);
        DB::table('invoices')->where('id', $invoice->id)->update(['archived_at' => now()]);
        DB::table('journal_entries')->where('id', $invoice->journal_entry_id)->update(['archived_at' => now()]);
        $invoiceBefore = (array) DB::table('invoices')->where('id', $invoice->id)->first();

        $this->actAsOrg()->put("/invoices/{$invoice->id}/project", ['project_id' => $other->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($other->id, LineAllocation::sole()->cost_center_id);
        $this->assertSame($other->id, InvoiceProject::sole()->cost_center_id);
        $this->assertEquals($invoiceBefore, (array) DB::table('invoices')->where('id', $invoice->id)->first(), 'The invoice record stays untouched.');
        $this->assertSame(1, JournalEntry::query()->whereKey($invoice->journal_entry_id)->value('revision'));

        $this->actAsOrg()->put("/invoices/{$invoice->id}/project", ['project_id' => null])->assertSessionHasNoErrors();
        $this->assertSame(0, LineAllocation::count());
        $this->assertSame(0, InvoiceProject::count());
    }

    public function test_a_credit_note_keeps_the_project_of_its_invoice(): void
    {
        $invoice = $this->createInvoice();
        app(InvoiceProjectService::class)->assign($invoice, $this->website->id);
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);

        $creditNote = app(CreateCreditNoteAction::class)->execute($invoice);
        app(FinalizeInvoiceAction::class)->execute($creditNote->fresh());

        $this->assertSame($this->website->id, InvoiceProject::query()->where('invoice_id', $creditNote->id)->value('cost_center_id'));
        $creditLine = TransactionLine::query()
            ->where('journal_entry_id', $creditNote->fresh()->journal_entry_id)
            ->where('account_id', $this->revenueAccount->id)
            ->sole();
        $this->assertSame($this->website->id, LineAllocation::query()->where('transaction_line_id', $creditLine->id)->value('cost_center_id'));
    }

    public function test_the_web_form_sets_the_project_before_finalizing(): void
    {
        $this->actAsOrg()->post('/invoices', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-03-16',
            'due_date' => '2026-04-15',
            'currency' => 'CHF',
            'tax_treatment' => 'standard',
            'finalize' => true,
            'project_id' => $this->website->id,
            'lines' => [['type' => 'item', 'description' => 'Work', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate_id' => $this->vatRate->id]],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->website->id, LineAllocation::sole()->cost_center_id);
    }

    public function test_the_create_form_offers_projects_with_their_customer(): void
    {
        $this->actAsOrg()->get('/invoices/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projectOptions.1.code', 'WEB')
                ->where('projectOptions.1.contact_id', $this->customer->id)
                ->where('projectOptions.1.is_open', true)
                ->where('projectOptions.0.is_open', false));

        $this->assertSame($this->website->id, app(InvoiceProjectService::class)->suggestionForCustomer($this->org->id, $this->customer->id));
        $this->makeProject('WEB2')->update(['contact_id' => $this->customer->id]);
        $this->assertNull(app(InvoiceProjectService::class)->suggestionForCustomer($this->org->id, $this->customer->id), 'Two open projects: no obvious choice.');
    }

    public function test_invoices_show_no_project_field_while_the_module_is_off(): void
    {
        $this->org->update(['enabled_modules' => ['projects' => false]]);

        $this->actAsOrg()->get('/invoices/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('projectOptions', null));

        $invoice = $this->createInvoice();
        app(InvoiceProjectService::class)->assign($invoice, $this->website->id);
        app(FinalizeInvoiceAction::class)->execute($invoice);
        $this->assertSame(0, LineAllocation::count());
        $this->assertSame(0, InvoiceProject::count());
    }

    public function test_correcting_a_payment_date_keeps_the_allocations(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        $payment = app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: (string) $invoice->total,
            paymentDate: '2026-04-01',
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));
        $bankLine = TransactionLine::query()->where('journal_entry_id', $payment->journal_entry_id)->where('account_id', $this->bankAccount->id)->sole();
        app(LineAllocationService::class)->replaceForEntry(JournalEntry::findOrFail($payment->journal_entry_id), [
            $bankLine->id => [new LineAllocationData(projectId: $this->website->id, employeeId: null)],
        ], AllocationSource::Manual);

        $updated = app(UpdatePaymentDateAction::class)->execute($invoice, $payment, '2026-04-15');

        $newBankLine = TransactionLine::query()->where('journal_entry_id', $updated->journal_entry_id)->where('account_id', $this->bankAccount->id)->sole();
        $this->assertSame($this->website->id, LineAllocation::query()->where('transaction_line_id', $newBankLine->id)->value('cost_center_id'));
    }

    public function test_the_overview_shows_the_spreadsheet_figures(): void
    {
        $this->website->update(['expected_amount' => '2000.00', 'target_date' => '2026-04-10']);
        $invoice = $this->createInvoice();
        app(InvoiceProjectService::class)->assign($invoice, $this->website->id);
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);
        app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: '500.00',
            paymentDate: '2026-04-01',
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));
        app(LedgerService::class)->postEntry($this->org->id, new JournalEntryData('2026-03-20', 'COST-1', 'Freelancer', [
            new JournalLineData(accountId: (string) $this->expenseAccount->id, debit: '200.00', credit: '0', allocations: $this->whole($this->website)),
            new JournalLineData(accountId: (string) $this->bankAccount->id, debit: '0', credit: '200.00'),
        ]));

        $overview = app(ProjectOverviewService::class)->overview($this->org->id, '2026-01-01', '2026-12-31', Carbon::parse('2026-04-01'));
        $rows = array_column($overview['rows'], null, 'code');

        $web = $rows['WEB'];
        $this->assertSame('1500.00', $web['revenue']);
        $this->assertSame('200.00', $web['expenses']);
        $this->assertSame('1300.00', $web['net']);
        $this->assertSame((string) $invoice->total, $web['invoiced']);
        $this->assertSame('500.00', $web['received']);
        $this->assertSame(Money::subtract((string) $invoice->total, '500.00'), $web['open']);
        $this->assertSame('partial', $web['payment']);
        $this->assertSame('critical', $web['deadline']);
        $this->assertSame('AREA Project AREA', $web['area']);

        $this->assertSame('1300.00', $rows['AREA']['net'], 'An area shows the sum of its projects.');
        $this->assertSame('1300.00', $overview['totals']['net'], 'Areas are not counted twice in the totals.');
        $byStatus = array_column($overview['by_status'], null, 'key');
        $this->assertSame('2000.00', $byStatus['in_progress']['expected']);
        $this->assertSame('1300.00', array_column($overview['by_area'], null, 'key')['AREA Project AREA']['net']);
    }

    public function test_the_deadline_follows_the_spreadsheet_rules(): void
    {
        $today = Carbon::parse('2026-06-01');
        $cases = [
            ['2026-05-31', ProjectStatus::InProgress, 'overdue'],
            ['2026-06-15', ProjectStatus::InProgress, 'critical'],
            ['2026-06-16', ProjectStatus::Possible, 'on_track'],
            [null, ProjectStatus::Possible, 'none'],
            ['2026-01-01', ProjectStatus::Done, 'done'],
            ['2026-01-01', ProjectStatus::Lost, 'done'],
        ];

        foreach ($cases as $i => [$date, $status, $expected]) {
            $this->makeProject("D{$i}")->update(['target_date' => $date, 'status' => $status]);
        }

        $rows = array_column(app(ProjectOverviewService::class)->overview($this->org->id, '2026-01-01', '2026-12-31', $today)['rows'], null, 'code');
        foreach ($cases as $i => [, , $expected]) {
            $this->assertSame($expected, $rows["D{$i}"]['deadline'], "Case D{$i}");
        }
    }

    public function test_the_overview_page_and_its_csv_answer(): void
    {
        $this->actAsOrg()->get('/projects?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('year', 2026)->has('overview.rows', 2));

        $csv = $this->actAsOrg()->get('/projects/export?year=2026');
        $csv->assertOk();
        $this->assertStringContainsString('WEB', $csv->streamedContent());
    }

    public function test_unallocated_lines_come_with_suggestions_and_can_be_taken_over(): void
    {
        // 1. an invoice without project of a customer with one open project
        app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        // 2. a cost like one allocated before
        app(LedgerService::class)->postEntry($this->org->id, new JournalEntryData('2026-02-10', 'SW-1', 'Software 01/2026', [
            new JournalLineData(accountId: (string) $this->expenseAccount->id, debit: '30.00', credit: '0', allocations: $this->whole($this->area)),
            new JournalLineData(accountId: (string) $this->bankAccount->id, debit: '0', credit: '30.00'),
        ]));
        app(LedgerService::class)->postEntry($this->org->id, new JournalEntryData('2026-03-10', 'SW-2', 'Software 02/2026', [
            new JournalLineData(accountId: (string) $this->expenseAccount->id, debit: '30.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->bankAccount->id, debit: '0', credit: '30.00'),
        ]));

        // Revenue and expenses sit on two tabs (spec 010).
        $lines = collect(['revenue', 'expense'])
            ->flatMap(fn (string $tab) => $this->actAsOrg()->get("/projects/unallocated?year=2026&tab={$tab}")->assertOk()->viewData('page')['props']['lines'])
            ->keyBy('reference');

        $this->assertSame('customer', $lines['INV-2026-001']['suggestion']['source']);
        $this->assertSame($this->website->id, $lines['INV-2026-001']['suggestion']['project_id']);
        $this->assertSame('history', $lines['SW-2']['suggestion']['source']);
        $this->assertSame($this->area->id, $lines['SW-2']['suggestion']['project_id']);
        $this->assertFalse($lines->has('SW-1'), 'Allocated lines are not listed.');

        $this->actAsOrg()->post('/projects/unallocated', ['items' => [
            ['line_id' => $lines['SW-2']['line_id'], 'project_id' => $this->area->id],
            ['line_id' => $lines['INV-2026-001']['line_id'], 'project_id' => $this->website->id],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(3, LineAllocation::count());
        $after = collect(['revenue', 'expense'])
            ->flatMap(fn (string $tab) => $this->actAsOrg()->get("/projects/unallocated?year=2026&tab={$tab}")->viewData('page')['props']['lines']);
        $this->assertCount(0, $after);
    }

    public function test_a_project_with_invoices_is_not_deleted(): void
    {
        $other = $this->makeProject('LINKED');
        app(InvoiceProjectService::class)->assign($this->createInvoice(), $other->id);

        $this->actAsOrg()->delete("/projects/{$other->id}")->assertSessionHasErrors('project');
        $this->assertNotNull($other->fresh());
    }

    public function test_a_credit_note_reduces_invoiced_and_open(): void
    {
        $invoice = $this->createInvoice();
        app(InvoiceProjectService::class)->assign($invoice, $this->website->id);
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);
        app(FinalizeInvoiceAction::class)->execute(app(CreateCreditNoteAction::class)->execute($invoice)->fresh());

        $web = collect(app(ProjectOverviewService::class)->overview($this->org->id, '2026-01-01', '2026-12-31')['rows'])->firstWhere('code', 'WEB');

        $this->assertSame('0.00', $web['invoiced']);
        $this->assertSame('0.00', $web['open']);
        $this->assertSame('0.00', $web['revenue']);
    }

    public function test_changing_the_project_of_a_cancelled_invoice_leaves_its_bookings(): void
    {
        $other = $this->makeProject('OTHER');
        $invoice = $this->createInvoice();
        app(InvoiceProjectService::class)->assign($invoice, $this->website->id);
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);
        app(CancelInvoiceAction::class)->execute($invoice->fresh());

        app(InvoiceProjectService::class)->assign($invoice->fresh(), $other->id);

        $this->assertSame(0, LineAllocation::query()->where('cost_center_id', $other->id)->count());
        $this->assertSame($other->id, InvoiceProject::query()->where('invoice_id', $invoice->id)->value('cost_center_id'));
    }

    public function test_allocations_to_an_area_itself_count_in_the_totals(): void
    {
        app(LedgerService::class)->postEntry($this->org->id, new JournalEntryData('2026-03-20', 'AREA-COST', 'Area cost', [
            new JournalLineData(accountId: (string) $this->expenseAccount->id, debit: '75.00', credit: '0', allocations: $this->whole($this->area)),
            new JournalLineData(accountId: (string) $this->bankAccount->id, debit: '0', credit: '75.00'),
        ]));

        $overview = app(ProjectOverviewService::class)->overview($this->org->id, '2026-01-01', '2026-12-31');

        $this->assertSame('75.00', $overview['totals']['expenses']);
    }

    public function test_taking_over_a_suggestion_keeps_the_person_of_a_line(): void
    {
        $person = Employee::factory()->for($this->org)->create();
        $entry = app(LedgerService::class)->postEntry($this->org->id, new JournalEntryData('2026-03-20', 'P-COST', 'x', [
            new JournalLineData(accountId: (string) $this->expenseAccount->id, debit: '40.00', credit: '0', allocations: [new LineAllocationData(projectId: null, employeeId: $person->id)]),
            new JournalLineData(accountId: (string) $this->bankAccount->id, debit: '0', credit: '40.00'),
        ]));
        $line = TransactionLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $this->expenseAccount->id)->sole();

        $this->actAsOrg()->post('/projects/unallocated', ['items' => [['line_id' => $line->id, 'project_id' => $this->website->id]]])->assertSessionHasNoErrors();

        $allocation = LineAllocation::query()->where('transaction_line_id', $line->id)->sole();
        $this->assertSame($this->website->id, $allocation->cost_center_id);
        $this->assertSame($person->id, $allocation->employee_id);
    }

    private function createInvoice(string $number = 'INV-2026-001'): Invoice
    {
        return app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->org->id,
            customerId: $this->customer->id,
            number: $number,
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
            taxTreatment: InvoiceTaxTreatment::Standard,
        ));
    }
}
