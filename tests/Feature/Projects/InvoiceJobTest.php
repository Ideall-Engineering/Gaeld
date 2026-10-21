<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CancelInvoiceAction;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\InvoiceProjectService;
use App\Domains\Projects\Services\SteeringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Every booked invoice belongs to a job: one without gets its own, and a
 * job can start its invoice.
 */
class InvoiceJobTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->makeAccount('1100', 'Receivables', AccountType::Asset);
        $this->customer = Contact::create(['organization_id' => $this->organization->id, 'name' => 'Inglin AG']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_invoice_booked_without_a_job_gets_its_own(): void
    {
        $this->makeProject('P26-07');
        $invoice = $this->book($this->draft('INV-1', '1770.00'));

        $job = $this->jobOf($invoice);
        $this->assertSame(['P26-08', 'Inglin AG (INV-1)', ProjectStatus::Done, '1770.00', $this->customer->id, null],
            [$job->code, $job->name, $job->status, (string) $job->expected_amount, $job->contact_id, $job->parent_id]);
        $this->assertTrue(LineAllocation::query()->where('cost_center_id', $job->id)->exists(), 'The revenue goes to the new job.');

        // In the steering it is an open invoice of that job, not a rest on top.
        $data = app(SteeringService::class)->forYear($this->organization->id, 2026);
        $this->assertSame(['P26-08'], array_column($data['open_invoices'], 'job_code'));
        $this->assertNotContains($job->id, array_column($data['open_payments'], 'id'));
    }

    public function test_an_invoice_with_a_job_gets_no_other(): void
    {
        $job = $this->makeProject('P26-51');
        $invoice = $this->draft('INV-2', '2000.00');
        app(InvoiceProjectService::class)->assign($invoice, $job->id);
        $this->book($invoice);

        $this->assertSame($job->id, $this->jobOf($invoice)->id);
        $this->assertSame(1, Project::query()->count());
    }

    public function test_moving_the_invoice_to_a_job_drops_the_one_made_for_it(): void
    {
        $invoice = $this->book($this->draft('INV-3', '1770.00'));
        $own = $this->jobOf($invoice);
        $target = $this->makeProject('P26-51');

        app(InvoiceProjectService::class)->assign($invoice->fresh(), $target->id);

        $this->assertSame($target->id, $this->jobOf($invoice)->id);
        $this->assertNull(Project::query()->find($own->id));
        $this->assertTrue(LineAllocation::query()->where('cost_center_id', $target->id)->exists());
    }

    public function test_clearing_the_job_of_a_booked_invoice_keeps_its_own(): void
    {
        $invoice = $this->book($this->draft('INV-4', '500.00'));
        $own = $this->jobOf($invoice);

        app(InvoiceProjectService::class)->assign($invoice->fresh(), null);

        $this->assertSame($own->id, $this->jobOf($invoice)->id);
        $this->assertSame(1, Project::query()->count());
    }

    public function test_a_cancelled_invoice_loses_the_job_made_for_it(): void
    {
        $invoice = $this->book($this->draft('INV-5', '500.00'));
        $own = $this->jobOf($invoice);

        app(CancelInvoiceAction::class)->execute($invoice->fresh());

        $this->assertSame(ProjectStatus::Lost, $own->fresh()->status);
    }

    public function test_an_older_invoice_without_a_job_gets_one_from_the_steering(): void
    {
        $invoice = $this->book($this->draft('INV-6', '800.00'));
        // As booked before every invoice got its job.
        $old = $this->jobOf($invoice);
        InvoiceProject::query()->where('invoice_id', $invoice->id)->delete();
        LineAllocation::query()->where('cost_center_id', $old->id)->delete();
        $old->delete();

        $this->actAsOrg()->post("/invoices/{$invoice->id}/job")->assertSessionHasNoErrors();

        $job = $this->jobOf($invoice);
        $this->assertSame('Inglin AG (INV-6)', $job->name);
        $this->assertTrue(LineAllocation::query()->where('cost_center_id', $job->id)->exists());
    }

    public function test_a_job_starts_its_invoice_with_what_is_left(): void
    {
        $job = $this->makeProject('P26-24');
        $job->update(['status' => ProjectStatus::InProgress, 'expected_amount' => '5000.00', 'contact_id' => $this->customer->id]);
        $first = $this->draft('INV-7', '2000.00');
        app(InvoiceProjectService::class)->assign($first, $job->id);
        $this->book($first);

        $this->actAsOrg()->get("/invoices/create?project_id={$job->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prefill.customer_id', (string) $this->customer->id)
                ->where('prefill.project_id', (string) $job->id)
                ->where('prefill.description', 'Project P26-24')
                ->where('prefill.unit_price', '3000.00'));
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

    private function jobOf(Invoice $invoice): Project
    {
        return Project::query()->findOrFail(InvoiceProject::query()->where('invoice_id', $invoice->id)->value('cost_center_id'));
    }
}
