<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CancelInvoiceAction;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\PaymentMethod;
use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\InvoiceProjectService;
use App\Domains\Projects\Services\ProjectOverviewService;
use App\Domains\Projects\Services\QuoteJobStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * A job follows its invoices: a sent invoice makes it "invoice sent", and
 * only once every invoice on it is paid is it done.
 */
class InvoiceJobStatusTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private Project $job;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->makeAccount('1100', 'Receivables', AccountType::Asset);
        $this->customer = Contact::create(['organization_id' => $this->organization->id, 'name' => 'Inglin AG']);
        $this->job = $this->makeProject('P26-30');
        $this->job->update(['status' => ProjectStatus::InProgress]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sending_an_invoice_marks_the_job_invoiced(): void
    {
        $this->book($this->onJob('INV-1', '1000.00'));

        $this->assertSame(ProjectStatus::Invoiced, $this->job->fresh()->status);
    }

    public function test_the_job_is_done_only_once_the_invoice_is_paid(): void
    {
        $invoice = $this->book($this->onJob('INV-1', '1000.00'));

        $this->pay($invoice, '400.00');
        $this->assertSame(ProjectStatus::Invoiced, $this->job->fresh()->status, 'A part payment leaves it open.');

        $this->pay($invoice->fresh(), '600.00');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(ProjectStatus::Done, $this->job->fresh()->status);
    }

    public function test_a_job_with_a_second_open_invoice_is_not_done(): void
    {
        $first = $this->book($this->onJob('INV-1', '1000.00'));
        $this->book($this->onJob('INV-2', '500.00'));

        $this->pay($first, '1000.00');

        $this->assertSame(ProjectStatus::Invoiced, $this->job->fresh()->status);
    }

    public function test_removing_the_payment_reopens_the_job(): void
    {
        $invoice = $this->book($this->onJob('INV-1', '1000.00'));
        $payment = $this->pay($invoice, '1000.00');
        $this->assertSame(ProjectStatus::Done, $this->job->fresh()->status);

        app(InvoiceAccountingService::class)->removePayment($invoice->fresh(), $payment);

        $this->assertSame(ProjectStatus::Invoiced, $this->job->fresh()->status);
    }

    public function test_cancelling_the_only_invoice_puts_the_job_back_in_progress(): void
    {
        $invoice = $this->book($this->onJob('INV-1', '1000.00'));

        app(CancelInvoiceAction::class)->execute($invoice->fresh());

        $this->assertSame(ProjectStatus::InProgress, $this->job->fresh()->status);
    }

    public function test_a_job_made_for_an_invoice_starts_as_invoice_sent(): void
    {
        $invoice = $this->book($this->draft('INV-1', '1000.00'));
        $own = $this->jobOf($invoice);
        $this->assertSame(ProjectStatus::Invoiced, $own->status);

        $this->pay($invoice, '1000.00');

        $this->assertSame(ProjectStatus::Done, $own->fresh()->status);
    }

    public function test_an_overdue_invoice_leaves_a_done_job_alone(): void
    {
        // A job closed by hand before this status existed, its invoice open.
        $invoice = $this->book($this->onJob('INV-1', '1000.00'));
        $this->job->update(['status' => ProjectStatus::Done]);

        $invoice->fresh()->update(['status' => InvoiceStatus::Overdue]);

        $this->assertSame(ProjectStatus::Done, $this->job->fresh()->status);
    }

    public function test_a_lost_job_is_left_alone(): void
    {
        $this->job->update(['status' => ProjectStatus::Lost]);

        $this->book($this->onJob('INV-1', '1000.00'));

        $this->assertSame(ProjectStatus::Lost, $this->job->fresh()->status);
    }

    public function test_moving_a_paid_invoice_to_another_job_updates_both(): void
    {
        $invoice = $this->book($this->onJob('INV-1', '1000.00'));
        $this->pay($invoice, '1000.00');
        $other = $this->makeProject('P26-31');
        $other->update(['status' => ProjectStatus::InProgress]);

        app(InvoiceProjectService::class)->assign($invoice->fresh(), $other->id);

        $this->assertSame(ProjectStatus::Done, $other->fresh()->status);
        $this->assertSame(ProjectStatus::InProgress, $this->job->fresh()->status);
    }

    public function test_a_further_quote_does_not_move_an_invoiced_job_back(): void
    {
        $this->job->update(['status' => ProjectStatus::Invoiced]);
        $quote = Quote::create([
            'organization_id' => $this->organization->id,
            'customer_id' => $this->customer->id,
            'cost_center_id' => $this->job->id,
            'number' => 'Q-1',
            'status' => QuoteStatus::Accepted,
            'issue_date' => '2026-11-01',
            'currency' => 'CHF',
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total' => '100.00',
        ]);

        app(QuoteJobStatusService::class)->follow($quote);

        $this->assertSame(ProjectStatus::Invoiced, $this->job->fresh()->status);
    }

    public function test_an_invoiced_job_is_no_longer_offered_for_new_invoices(): void
    {
        $this->job->update(['contact_id' => $this->customer->id]);
        $this->assertSame($this->job->id, app(InvoiceProjectService::class)->suggestionForCustomer($this->organization->id, $this->customer->id));

        $this->book($this->onJob('INV-1', '1000.00'));

        $this->assertNull(app(InvoiceProjectService::class)->suggestionForCustomer($this->organization->id, $this->customer->id));
    }

    public function test_existing_jobs_are_tidied_from_their_invoices(): void
    {
        $open = $this->job;
        $this->book($this->onJob('INV-1', '1000.00'));
        $paid = $this->makeProject('P26-40');
        $this->job = $paid;
        $this->pay($this->book($this->onJob('INV-2', '500.00')), '500.00');
        $none = $this->makeProject('P26-41');
        $quoted = $this->makeProject('P26-42');
        $lost = $this->makeProject('P26-43');
        // As they stood before the status existed.
        Project::query()->whereIn('id', [$open->id, $paid->id, $none->id])->update(['status' => ProjectStatus::Done->value]);
        $quoted->update(['status' => ProjectStatus::Possible]);
        $lost->update(['status' => ProjectStatus::Lost]);

        $this->artisan('gaeld:auftraege-nach-rechnungen')->assertSuccessful();
        $this->assertSame(ProjectStatus::Done, $open->fresh()->status, 'Without --apply nothing changes.');

        $this->artisan('gaeld:auftraege-nach-rechnungen', ['--apply' => true])->assertSuccessful();

        $this->assertSame(ProjectStatus::Invoiced, $open->fresh()->status);
        $this->assertSame(ProjectStatus::Done, $paid->fresh()->status);
        $this->assertSame(ProjectStatus::Done, $none->fresh()->status, 'A job without an invoice in Gäld was billed elsewhere.');
        $this->assertSame(ProjectStatus::Possible, $quoted->fresh()->status);
        $this->assertSame(ProjectStatus::Lost, $lost->fresh()->status);
    }

    public function test_the_job_page_shows_its_invoices_and_what_is_left_to_invoice(): void
    {
        $this->job->update(['expected_amount' => '1000.00']);
        $invoice = $this->book($this->onJob('INV-1', '1000.00'));
        $this->pay($invoice, '400.00');

        $this->actAsOrg()->get("/projects/{$this->job->id}/report")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('invoices', 1)
                ->where('invoices.0.id', $invoice->id)
                ->where('invoices.0.number', 'INV-1')
                ->where('invoices.0.status', 'sent')
                ->where('invoices.0.open', '600.00')
                ->where('rest', '0.00'));

        $this->job->update(['expected_amount' => '1500.00']);
        $this->actAsOrg()->get("/projects/{$this->job->id}/report")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rest', '500.00'));
    }

    public function test_a_job_without_an_expected_amount_takes_the_invoice_net(): void
    {
        $this->job->update(['expected_amount' => null]);
        $this->onJob('INV-1', '1000.00');
        $this->assertSame('1000.00', (string) $this->job->fresh()->expected_amount);

        $this->job->update(['expected_amount' => '2500.00']);
        $this->onJob('INV-2', '400.00');
        $this->assertSame('2500.00', (string) $this->job->fresh()->expected_amount, 'An amount set by hand stays.');
    }

    public function test_the_job_list_flags_counted_jobs_without_an_expected_amount(): void
    {
        $this->job->update(['expected_amount' => null]);
        $open = $this->makeProject('P26-31');
        $open->update(['status' => ProjectStatus::Possible, 'expected_amount' => null]);

        $rows = collect(app(ProjectOverviewService::class)->overview($this->organization->id, '2026-01-01', '2026-12-31')['rows'])->keyBy('code');

        $this->assertTrue($rows['P26-30']['needs_expected']);
        $this->assertFalse($rows['P26-31']['needs_expected'], 'A job not yet given needs no amount yet.');
    }

    private function onJob(string $number, string $amount): Invoice
    {
        $invoice = $this->draft($number, $amount);
        app(InvoiceProjectService::class)->assign($invoice, $this->job->id);

        return $invoice;
    }

    private function draft(string $number, string $amount): Invoice
    {
        return app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->organization->id,
            customerId: $this->customer->id,
            number: $number,
            issueDate: '2026-10-15',
            dueDate: '2026-11-18',
            currency: 'CHF',
            notes: null,
            paymentTerms: null,
            lines: [InvoiceLineData::fromArray(['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount])],
        ));
    }

    private function book(Invoice $invoice): Invoice
    {
        return app(FinalizeInvoiceAction::class)->execute($invoice);
    }

    private function pay(Invoice $invoice, string $amount): InvoicePayment
    {
        return app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: $amount,
            paymentDate: now()->toDateString(),
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));
    }

    private function jobOf(Invoice $invoice): Project
    {
        return Project::query()->findOrFail(InvoiceProject::query()->where('invoice_id', $invoice->id)->value('cost_center_id'));
    }
}
