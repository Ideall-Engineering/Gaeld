<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Expenses\Actions\CreateExpenseAction;
use App\Domains\Expenses\Jobs\GenerateRecurringExpensesJob;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Domains\Invoicing\Enums\RecurrenceFrequency;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Services\PlanActualService;
use App\Domains\Projects\Services\SteeringService;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Plan against actual per cost block (spec 009, User Story 10).
 */
class PlanActualTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Account $software1;

    private Account $software2;

    private Account $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->software1 = $this->makeAccount('6031', 'Software A', AccountType::Expense);
        $this->software2 = $this->makeAccount('6034', 'Software B', AccountType::Expense);
        $this->marketing = $this->makeAccount('6600', 'Marketing', AccountType::Expense);
    }

    public function test_actual_rest_and_projection_of_a_block(): void
    {
        $group = $this->group('Software', [$this->software1, $this->software2], '1800.00');
        $this->cost($this->software1, '600.00', '2026-02-10');
        $this->cost($this->software2, '300.00', '2026-03-10');
        $this->cost($this->software1, '50.00', '2025-12-31');

        $row = $this->row('2026-07-02', $group);

        $this->assertSame('1800.00', $row['plan']);
        $this->assertSame('900.00', $row['actual']);
        $this->assertSame('900.00', $row['rest']);
        $this->assertSame('1795.08', $row['projection'], '183 of 365 days have passed.');
        $this->assertFalse($row['over_plan']);
        $this->assertSame(50, $row['progress']);
    }

    public function test_a_one_off_block_is_not_projected_and_a_running_one_is_flagged_over_plan(): void
    {
        $oneOff = $this->group('Material', [$this->software1], '100.00', BudgetGroupKind::OneOff);
        $running = $this->group('Marketing', [$this->marketing], '100.00');
        $this->cost($this->software1, '80.00', '2026-01-20');
        $this->cost($this->marketing, '80.00', '2026-01-20');

        $this->assertSame('80.00', $this->row('2026-04-01', $oneOff)['projection']);
        $this->assertTrue($this->row('2026-04-01', $running)['over_plan']);
    }

    public function test_without_an_amount_the_plan_comes_from_the_account_budgets(): void
    {
        $group = $this->group('Software', [$this->software1, $this->software2], null);
        Budget::create(['organization_id' => $this->organization->id, 'account_id' => $this->software1->id, 'fiscal_year' => 2026, 'monthly_amount' => '100.00']);
        Budget::create(['organization_id' => $this->organization->id, 'account_id' => $this->software2->id, 'fiscal_year' => 2026, 'monthly_amount' => '50.00']);

        $row = $this->row('2026-07-01', $group);

        $this->assertSame('1800.00', $row['plan']);
        $this->assertTrue($row['plan_from_budgets']);
    }

    public function test_recurring_expenses_add_to_the_plan_of_their_account_from_their_start(): void
    {
        $group = $this->group('Miete', [$this->software1, $this->software2], null);
        $next = $this->group('Miete', [$this->software1], null, year: 2027);
        $fixed = $this->group('Marketing', [$this->marketing], '500.00');
        Budget::create(['organization_id' => $this->organization->id, 'account_id' => $this->software2->id, 'fiscal_year' => 2026, 'monthly_amount' => '10.00']);
        $this->recurring($this->software1, '1366.00', '2026-10-01');
        $this->recurring($this->marketing, '50.00', '2026-01-15', RecurrenceFrequency::Quarterly);
        // Paused without an end: counts nowhere.
        $this->recurring($this->software1, '999.00', '2026-01-01')->update(['is_active' => false]);

        $row = $this->row('2026-10-18', $group);
        $this->assertSame('4218.00', $row['plan'], 'October to December rent plus twelve months of budget.');
        $this->assertSame([3], array_column($row['recurring'], 'count'));
        $this->assertSame('16392.00', $this->row('2026-10-18', $next, 2027)['plan']);
        $this->assertSame('500.00', $this->row('2026-10-18', $fixed)['plan'], 'A set amount is not topped up.');

        $block = collect(app(SteeringService::class)->forYear($this->organization->id, 2026)['blocks'])->firstWhere('id', $group->id);
        $this->assertSame('4218.00', $block['plan']);
        $this->assertSame('4098.00', $block['accounts'][0]['yearly']);
    }

    public function test_a_plan_only_schedule_moves_on_without_creating_an_expense(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $rent = $this->recurring($this->software1, '1366.00', '2026-11-01');
        $rent->update(['creates_expense' => false, 'starts_on' => '2026-10-01']);

        app(GenerateRecurringExpensesJob::class)->handle(app(CreateExpenseAction::class));

        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('2026-12-01', $rent->fresh()->next_due_date->toDateString());
        $this->assertSame('2026-10-01', $rent->fresh()->starts_on->toDateString());
    }

    public function test_shares_of_customer_projects_are_not_internal(): void
    {
        $group = $this->group('Software', [$this->software1], '1000.00');
        $customer = $this->makeProject('CUST');
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-03-01', 'SPLIT', 'x', [
            new JournalLineData(accountId: (string) $this->software1->id, debit: '200.00', credit: '0', allocations: [
                new LineAllocationData(projectId: $customer->id, employeeId: null, share: '50'),
            ]),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '200.00'),
        ]));

        $row = $this->row('2026-12-31', $group);

        $this->assertSame('100.00', $row['actual']);
        $this->assertSame('100.00', $row['customer_projects']);
    }

    public function test_a_block_unfolds_to_its_bookings(): void
    {
        $group = $this->group('Software', [$this->software1, $this->software2], '1000.00');
        $customer = $this->makeProject('CUST');
        $this->cost($this->software2, '40.00', '2026-02-01');
        $this->cost($this->marketing, '70.00', '2026-02-02');
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-03-01', 'SPLIT', 'x', [
            new JournalLineData(accountId: (string) $this->software1->id, debit: '200.00', credit: '0', allocations: [
                new LineAllocationData(projectId: $customer->id, employeeId: null, share: '60'),
            ]),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '200.00'),
        ]));

        $rows = $this->actAsOrg()->getJson("/projects/plan/{$group->id}/lines")->assertOk()->json('rows');

        $this->assertCount(2, $rows);
        $this->assertSame(['2026-03-01', '2026-02-01'], array_column($rows, 'date'));
        $this->assertSame('200.00', $rows[0]['amount']);
        $this->assertSame('120.00', $rows[0]['at_jobs']);
        $this->assertSame([$customer->name], $rows[0]['jobs']);
        $this->assertSame('0.00', $rows[1]['at_jobs']);
        $this->assertSame((string) $this->bank->code, $rows[0]['counter']);
        $this->assertSame([], $rows[1]['jobs']);
        // What the general costs page needs to show and change the allocation.
        $this->assertSame([$customer->name.' 60 %'], $rows[0]['assigned']);
        $this->assertSame('200.00', $rows[0]['debit']);
        $this->assertSame('0', $rows[0]['credit']);
        $this->assertSame($customer->id, $rows[0]['allocations'][0]['project_id']);
        $this->assertSame([], $rows[1]['assigned']);
    }

    public function test_bookings_of_a_block_move_to_a_job_without_touching_other_lines_of_the_entry(): void
    {
        $group = $this->group('Software', [$this->software1], '1000.00');
        $job = $this->makeProject('JOB');
        $entry = app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-03-01', 'TWO', 'x', [
            new JournalLineData(accountId: (string) $this->software1->id, debit: '200.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->marketing->id, debit: '50.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '250.00'),
        ]));
        $line = $this->actAsOrg()->getJson("/projects/plan/{$group->id}/lines")->json('rows.0');

        $this->actAsOrg()->post('/accounting/journal-entry-allocations/bulk', [
            'entry_ids' => [$entry->id],
            'line_ids' => [$line['line_id']],
            'project_id' => $job->id,
            'overwrite' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame([$job->name], $this->actAsOrg()->getJson("/projects/plan/{$group->id}/lines")->json('rows.0.assigned'));
        $marketingLine = $entry->lines()->where('account_id', $this->marketing->id)->sole();
        $this->assertSame(0, LineAllocation::query()->where('transaction_line_id', $marketingLine->id)->count());
    }

    public function test_accounts_with_costs_outside_any_block_are_listed(): void
    {
        $this->group('Software', [$this->software1], '1000.00');
        $this->cost($this->marketing, '40.00', '2026-05-05');

        $plan = app(PlanActualService::class)->forYear($this->organization->id, 2026, Carbon::parse('2026-12-31'));

        $this->assertSame(['6600'], array_column($plan['ungrouped'], 'code'));
        $this->assertSame('40.00', $plan['ungrouped'][0]['actual']);
    }

    public function test_blocks_are_managed_on_the_page_and_an_account_belongs_to_one_block(): void
    {
        $this->actAsOrg()->post('/projects/plan', [
            'year' => 2026, 'name' => 'Software', 'kind' => 'running', 'plan_amount' => '1800',
            'account_ids' => [$this->software1->id, $this->software2->id],
        ])->assertSessionHasNoErrors();

        $this->actAsOrg()->post('/projects/plan', [
            'year' => 2026, 'name' => 'Other', 'kind' => 'running', 'account_ids' => [$this->software1->id],
        ])->assertSessionHasErrors('account_ids.0');

        $group = BudgetGroup::sole();
        $this->actAsOrg()->put("/projects/plan/{$group->id}", [
            'name' => 'Software & SaaS', 'kind' => 'running', 'plan_amount' => '', 'account_ids' => [$this->software1->id],
        ])->assertSessionHasNoErrors();
        $group->refresh();
        $this->assertSame('Software & SaaS', $group->name);
        $this->assertNull($group->plan_amount);
        $this->assertSame([$this->software1->id], $group->accounts->pluck('id')->all());

        // Spec 010: the blocks live on the jobs page; the old address leads there.
        $this->actAsOrg()->get('/projects/plan?year=2026')->assertRedirect('/projects?year=2026#general-costs');
        $this->actAsOrg()->get('/projects?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Index')
                ->where('plan.groups.0.name', 'Software & SaaS')
                ->has('blockOptions.accounts')
                ->has('blockOptions.kinds'));
        $this->assertStringContainsString('Software & SaaS', $this->actAsOrg()->get('/projects/plan/export?year=2026')->streamedContent());

        $this->actAsOrg()->delete("/projects/plan/{$group->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, BudgetGroup::count());
    }

    public function test_the_previous_year_is_copied_once(): void
    {
        $this->group('Software', [$this->software1], '1800.00', year: 2025);
        $this->group('Marketing', [$this->marketing], '200.00', year: 2025);
        $this->group('Marketing', [], '300.00', year: 2026);

        $this->actAsOrg()->post('/projects/plan/copy-previous', ['year' => 2026])->assertSessionHasNoErrors();
        $this->actAsOrg()->post('/projects/plan/copy-previous', ['year' => 2026]);

        $groups = BudgetGroup::query()->with('accounts')->where('year', 2026)->orderBy('name')->get();
        $this->assertSame(['Marketing', 'Software'], $groups->pluck('name')->all());
        $this->assertSame('300.00', $groups[0]->plan_amount, 'An existing block is kept as it is.');
        $this->assertSame([$this->software1->id], $groups[1]->accounts->pluck('id')->all());
    }

    public function test_a_viewer_sees_the_plan_but_cannot_change_it(): void
    {
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');
        $session = ['current_organization_id' => $this->organization->id];

        $this->actingAs($viewer)->withSession($session)->get('/projects')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('plan.groups'));
        $this->actingAs($viewer)->withSession($session)->post('/projects/plan', ['year' => 2026, 'name' => 'X', 'kind' => 'running'])->assertForbidden();
    }

    /** @param  array<int, Account>  $accounts */
    private function group(string $name, array $accounts, ?string $plan, BudgetGroupKind $kind = BudgetGroupKind::Running, int $year = 2026): BudgetGroup
    {
        $group = BudgetGroup::create([
            'organization_id' => $this->organization->id,
            'year' => $year,
            'name' => $name,
            'kind' => $kind,
            'plan_amount' => $plan,
        ]);
        $group->accounts()->sync(array_map(fn (Account $a) => $a->id, $accounts));

        return $group;
    }

    private function cost(Account $account, string $amount, string $date): void
    {
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData($date, null, 'Cost', [
            new JournalLineData(accountId: (string) $account->id, debit: $amount, credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: $amount),
        ]));
    }

    /** @return array<string, mixed> */
    private function row(string $asOf, BudgetGroup $group, int $year = 2026): array
    {
        $plan = app(PlanActualService::class)->forYear($this->organization->id, $year, Carbon::parse($asOf));

        return collect($plan['groups'])->firstWhere('id', $group->id);
    }

    private function recurring(Account $account, string $amount, string $start, RecurrenceFrequency $frequency = RecurrenceFrequency::Monthly): RecurringExpense
    {
        return RecurringExpense::create([
            'organization_id' => $this->organization->id,
            'category' => 'Miete',
            'description' => 'Büromiete',
            'amount' => $amount,
            'vat_amount' => '0.00',
            'expense_account_id' => $account->id,
            'expense_account_code' => $account->code,
            'frequency' => $frequency,
            'starts_on' => $start,
            'next_due_date' => $start,
            'is_active' => true,
        ]);
    }
}
