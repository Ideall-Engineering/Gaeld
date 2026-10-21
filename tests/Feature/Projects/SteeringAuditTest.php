<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\PaymentMethod;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectShare;
use App\Domains\Projects\Models\SteeringItem;
use App\Domains\Projects\Services\AllocationCoverageService;
use App\Domains\Projects\Services\ExpectedExpensesService;
use App\Domains\Projects\Services\InvoiceProjectService;
use App\Domains\Projects\Services\SteeringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Audit 2026-10-21: the steering figures against amounts worked out by hand.
 */
class SteeringAuditTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $anna;

    private Employee $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->anna = Employee::factory()->for($this->organization)->create(['first_name' => 'Anna', 'last_name' => 'A', 'gross_salary' => '0.00']);
        $this->ben = Employee::factory()->for($this->organization)->create(['first_name' => 'Ben', 'last_name' => 'B', 'gross_salary' => '0.00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A job of 10'000 invoiced in two parts: 3'000 booked in June, 7'000 still
     * to come in November. By hand: 10'000 counts, 3'000 received, 7'000 open.
     */
    public function test_a_partly_invoiced_job_keeps_its_rest_in_view(): void
    {
        $job = $this->makeProject('P1');
        $job->update(['status' => ProjectStatus::InProgress, 'expected_amount' => '10000.00', 'billing_month' => '2026-11-01']);
        $this->share($job, [$this->anna->id => '100']);
        $this->revenue('2026-06-15', '3000.00', $job);

        $data = app(SteeringService::class)->forYear($this->organization->id, 2026);
        $anna = collect($data['people'])->keyBy('name')['Anna A'];

        $this->assertSame('3000.00', $anna['contribution_received']);
        $this->assertSame('7000.00', $anna['contribution_expected'], 'The 7000 not yet invoiced should still be expected.');
        $this->assertSame('10000.00', $anna['contribution']);
        $this->assertSame(['P1'], array_column($data['open_payments'], 'code'), 'The rest should stay in the open payments.');
    }

    /**
     * A third person on the payroll (no key anywhere) and a block of 900
     * without a carry key. By hand, as in the spreadsheet: the two owners
     * bear 450 each.
     */
    public function test_a_block_without_key_is_borne_by_the_owners_only(): void
    {
        $area = $this->makeProject('AREA', kind: ProjectKind::Area);
        $this->share($area, [$this->anna->id => '50', $this->ben->id => '50']);
        Employee::factory()->for($this->organization)->create(['first_name' => 'Carla', 'last_name' => 'C', 'gross_salary' => '0.00']);
        BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Software', 'kind' => 'running', 'plan_amount' => '900.00']);

        $people = collect(app(SteeringService::class)->forYear($this->organization->id, 2026)['people'])->keyBy('name');

        $this->assertSame('-450.00', $people['Anna A']['effect']);
        $this->assertSame('-450.00', $people['Ben B']['effect']);
    }

    /**
     * A job of 10'000 invoiced from Gäld on 15 October, due 20 November,
     * not paid yet. By hand: nothing received, the 10'000 still expected,
     * and the bank gets 10'000 in November.
     */
    public function test_an_invoiced_but_unpaid_job_still_brings_money_to_the_bank(): void
    {
        BankAccount::create(['uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'account_id' => $this->bank->id, 'name' => 'Main', 'currency' => 'CHF', 'is_active' => true]);
        $this->postSimpleEntry('2026-01-05', 'CAPITAL', '5000.00', $this->bank, $this->revenue);
        $this->makeAccount('1100', 'Receivables', AccountType::Asset);
        $job = $this->makeProject('P2');
        $job->update(['status' => ProjectStatus::Done, 'expected_amount' => '10000.00']);
        $this->share($job, [$this->anna->id => '100']);
        $invoice = $this->invoice('2026-10-15', '2026-11-20', '10000.00', $job);

        $data = app(SteeringService::class)->forYear($this->organization->id, 2026);
        $anna = collect($data['people'])->keyBy('name')['Anna A'];
        $months = collect($data['outlook']['months'])->keyBy('key');

        $this->assertSame('10000.00', $anna['contribution']);
        $this->assertSame('0.00', $anna['contribution_received'], 'Invoiced is not received.');
        $this->assertSame('10000.00', $anna['contribution_expected']);
        $this->assertSame([$invoice->number], array_column($data['open_invoices'], 'number'));
        $this->assertSame('5000.00', $data['outlook']['bank_today']);
        $this->assertSame('0.00', $months['2026-11']['income'], 'The revenue is booked already, not in the result again.');
        $this->assertSame('10000.00', $months['2026-11']['cash_in']);
        $this->assertSame('15000.00', $months['2026-12']['bank']);

        // Paid: received, and nothing more to come.
        app(InvoiceAccountingService::class)->recordPayment($invoice->fresh(), new RecordPaymentData(amount: '10000.00', paymentDate: '2026-10-20', paymentMethod: PaymentMethod::Bank, reference: null));
        $data = app(SteeringService::class)->forYear($this->organization->id, 2026);
        $this->assertSame('10000.00', collect($data['people'])->keyBy('name')['Anna A']['contribution_received']);
        $this->assertSame([], $data['open_invoices']);
        $this->assertSame('15000.00', collect($data['outlook']['months'])->keyBy('key')['2026-12']['bank']);
    }

    /**
     * Anna's year is in the plan items (fixed salary 60'000), October to
     * December are not booked yet. By hand: drawn 60'000, the expected
     * salaries are not added again.
     */
    public function test_expected_salaries_are_not_drawn_twice(): void
    {
        $this->anna->update(['gross_salary' => '6000.00', 'entry_date' => '2025-01-01', 'has_thirteenth_salary' => false, 'expense_allowance' => '0.00']);
        SteeringItem::create(['organization_id' => $this->organization->id, 'year' => 2026, 'employee_id' => $this->anna->id, 'label' => 'Fixed salary, ten months', 'amount' => '60000.00']);

        $anna = collect(app(SteeringService::class)->forYear($this->organization->id, 2026)['people'])->keyBy('name')['Anna A'];

        $this->assertSame('60000.00', $anna['drawn']);
    }

    /**
     * A job lost after costs of 400 were booked on it, no key of its own,
     * the owners Anna and Ben. By hand: the 400 is borne 200 each.
     */
    public function test_costs_on_a_lost_job_are_borne_by_the_owners(): void
    {
        $other = $this->makeProject('KEY');
        $this->share($other, [$this->anna->id => '50', $this->ben->id => '50']);
        $lost = $this->makeProject('LOST');
        $lost->update(['status' => ProjectStatus::Lost]);
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-05-05', 'PROTO', 'Prototype', [
            new JournalLineData(accountId: (string) $this->otherExpense->id, debit: '400.00', credit: '0', allocations: $this->whole($lost)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '400.00'),
        ]));

        $people = collect(app(SteeringService::class)->forYear($this->organization->id, 2026)['people'])->keyBy('name');

        $this->assertSame('-200.00', $people['Anna A']['contribution']);
        $this->assertSame('-200.00', $people['Ben B']['general']);
    }

    /**
     * A job of 10'000 expected in December, standard rate 8.1 %. By hand: the
     * customer pays 10'810 in December, the 810 go to the tax office with Q4
     * on 1 March 2027.
     */
    public function test_the_bank_outlook_counts_vat_as_it_moves(): void
    {
        VatRate::create(['organization_id' => $this->organization->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $job = $this->makeProject('P4');
        $job->update(['status' => ProjectStatus::InProgress, 'expected_amount' => '10000.00', 'billing_month' => '2026-12-01']);
        $this->share($job, [$this->anna->id => '100']);

        $months = collect(app(SteeringService::class)->forYear($this->organization->id, 2026)['outlook']['months'])->keyBy('key');

        $this->assertSame(['10000.00', '10810.00'], [$months['2026-12']['income'], $months['2026-12']['cash_in']]);
        $this->assertSame('810.00', $months['2027-03']['cash_out']);
        $this->assertSame('10000.00', $months['2027-03']['bank']);
    }

    /**
     * BVG set up per person (as in the runbook) and posted on 5730, a cost
     * block on 5730 with 9 of 12 months booked. By hand: October to December
     * cost 3 x 85.55 = 256.65 once; the block has nothing left beyond that.
     */
    public function test_a_pension_rate_per_person_covers_its_cost_block(): void
    {
        $this->anna->update(['gross_salary' => '6000.00', 'entry_date' => '2025-01-01', 'has_thirteenth_salary' => false, 'expense_allowance' => '0.00']);
        $this->ben->update(['is_active' => false]);
        DeductionRate::create(['organization_id' => $this->organization->id, 'name' => 'AHV AG', 'code' => 'avs_employer', 'rate' => '5.3000', 'type' => 'employer', 'expense_account_code' => '5700', 'is_active' => true]);
        DeductionRate::create(['organization_id' => $this->organization->id, 'employee_id' => $this->anna->id, 'name' => 'BVG AG', 'code' => 'lpp_employer', 'amount' => '85.55', 'type' => 'employer', 'expense_account_code' => '5730', 'is_active' => true]);
        $pension = $this->makeAccount('5730', 'BVG', AccountType::Expense);
        $this->postSimpleEntry('2026-09-30', 'BVG-1-9', '769.95', $pension, $this->bank);
        $block = BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Pension', 'kind' => 'running', 'plan_amount' => '1026.60']);
        $block->accounts()->sync([$pension->id]);

        $data = app(ExpectedExpensesService::class)->forYear($this->organization->id, 2026);
        $social = collect($data['rows'])->where('source', 'social');

        $this->assertSame('256.65', $social->where('account', '5730')->reduce(fn (string $sum, array $r) => bcadd($sum, $r['amount'], 2), '0.00'));
        $this->assertSame('0.00', $data['by_source']['block'], 'The pension block is covered by the expected BVG rows.');
        $this->assertSame('19210.65', $data['total']);
    }

    /**
     * The form and the API accept a negative single item (an expected
     * refund). By hand: it lowers the expected expenses by 500.
     */
    public function test_a_negative_single_item_lowers_the_expected_expenses(): void
    {
        $this->actAsOrg()->post('/projects/steering/planned-expenses', ['date' => '2026-11-10', 'label' => 'Refund', 'amount' => '-500.00'])
            ->assertSessionHasNoErrors();

        $this->actAsOrg()->get('/projects/steering?year=2026')->assertOk();
        $this->assertSame('-500.00', app(ExpectedExpensesService::class)->forYear($this->organization->id, 2026)['total']);
    }

    /**
     * A one-off cost of 5'000 that belongs to no job and no cost block goes,
     * as the allocate inbox suggests, to the internal job "Allgemein". By
     * hand: the two owners, 50/50 on everything, bear 2'500 each.
     */
    public function test_a_one_off_general_cost_is_borne_by_someone(): void
    {
        $area = $this->makeProject('AREA', kind: ProjectKind::Area);
        $this->share($area, [$this->anna->id => '50', $this->ben->id => '50']);
        $general = app(AllocationCoverageService::class)->generalProject($this->organization->id);
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-05-05', 'LAW-1', 'Legal advice', [
            new JournalLineData(accountId: (string) $this->otherExpense->id, debit: '5000.00', credit: '0', allocations: $this->whole($general)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '5000.00'),
        ]));

        $data = app(SteeringService::class)->forYear($this->organization->id, 2026);

        $this->assertSame('-5000.00', $data['totals']['play'], 'The 5000 lowers what is left to play with.');
    }

    /**
     * A job planned to be paid in January 2027 but invoiced on 10 December
     * 2026. By hand: its 10'000 counts once, in 2026, where it is booked.
     */
    public function test_a_job_invoiced_before_its_planned_year_counts_once(): void
    {
        $job = $this->makeProject('P3');
        $job->update(['status' => ProjectStatus::InProgress, 'expected_amount' => '10000.00', 'payment_expected_on' => '2027-01-15']);
        $this->share($job, [$this->anna->id => '100']);
        $receivables = $this->makeAccount('1100', 'Receivables', AccountType::Asset);
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-12-10', 'INV-2', 'Invoice', [
            new JournalLineData(accountId: (string) $receivables->id, debit: '10000.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->revenue->id, debit: '0', credit: '10000.00', allocations: $this->whole($job)),
        ]));

        $in2026 = collect(app(SteeringService::class)->forYear($this->organization->id, 2026)['people'])->keyBy('name')['Anna A'];
        $in2027 = collect(app(SteeringService::class)->forYear($this->organization->id, 2027)['people'])->keyBy('name')['Anna A'];

        $this->assertSame('10000.00', $in2026['contribution']);
        $this->assertSame('0.00', $in2027['contribution'], 'Counted in 2026 already.');
    }

    /** The steering form sends the VAT field empty when nothing is typed in. */
    public function test_a_single_item_without_vat_is_saved_from_the_form(): void
    {
        $this->withoutExceptionHandling();
        $this->actAsOrg()->post('/projects/steering/planned-expenses', ['date' => '2026-11-10', 'label' => 'Insurance', 'amount' => '800.00', 'vat_amount' => '', 'account_code' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('800.00', app(ExpectedExpensesService::class)->forYear($this->organization->id, 2026)['total']);
    }

    /**
     * Hosting paid monthly on the 31st, next due 31 October. By hand:
     * October, November and December are still to pay, 3 x 50.
     */
    public function test_a_monthly_expense_due_at_the_month_end_counts_every_month(): void
    {
        RecurringExpense::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'category' => 'office',
            'expense_account_code' => '6500', 'frequency' => 'monthly', 'is_active' => true,
            'description' => 'Hosting', 'amount' => '50.00', 'vat_amount' => '0.00', 'next_due_date' => '2026-10-31',
        ]);

        $rows = collect(app(ExpectedExpensesService::class)->forYear($this->organization->id, 2026)['rows'])->where('source', 'recurring');

        $this->assertSame(['2026-10-31', '2026-11-30', '2026-12-31'], $rows->pluck('date')->values()->all());
    }

    private function invoice(string $issued, string $due, string $amount, Project $job): Invoice
    {
        $customer = Contact::create(['organization_id' => $this->organization->id, 'name' => 'Client AG']);
        $invoice = app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->organization->id,
            customerId: $customer->id,
            number: 'INV-'.$issued,
            issueDate: $issued,
            dueDate: $due,
            currency: 'CHF',
            notes: null,
            paymentTerms: null,
            lines: [InvoiceLineData::fromArray(['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount])],
        ));
        app(InvoiceProjectService::class)->assign($invoice, $job->id);

        return app(FinalizeInvoiceAction::class)->execute($invoice);
    }

    private function revenue(string $date, string $amount, Project $job): void
    {
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData($date, 'R-'.$date, 'Revenue', [
            new JournalLineData(accountId: (string) $this->bank->id, debit: $amount, credit: '0'),
            new JournalLineData(accountId: (string) $this->revenue->id, debit: '0', credit: $amount, allocations: $this->whole($job)),
        ]));
    }

    /** @param  array<string, string>  $shares  employee id => percent */
    private function share(Project $project, array $shares): void
    {
        foreach ($shares as $employeeId => $share) {
            ProjectShare::create([
                'organization_id' => $this->organization->id,
                'cost_center_id' => $project->id,
                'employee_id' => $employeeId,
                'share' => $share,
            ]);
        }
    }
}
