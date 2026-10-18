<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\AllocationCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Whether every receipt of a year has its place, and the inbox that gets
 * it there (spec 010, US1 and US2).
 */
class AllocationCoverageTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Account $salary;

    private Project $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->salary = $this->makeAccount('5600', 'Salaries', AccountType::Expense);
        $this->job = $this->makeProject('P26-01');
    }

    private function entry(string $reference, Account $debit, Account $credit, string $amount = '100.00', ?Project $project = null): JournalEntry
    {
        $allocate = fn (Account $account) => $project !== null && in_array($account->type, [AccountType::Revenue, AccountType::Expense], true) ? $this->whole($project) : null;

        return app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-05-04', $reference, "Text {$reference}", [
            new JournalLineData(accountId: (string) $debit->id, debit: $amount, credit: '0', allocations: $allocate($debit)),
            new JournalLineData(accountId: (string) $credit->id, debit: '0', credit: $amount, allocations: $allocate($credit)),
        ]));
    }

    private function coverage(): array
    {
        return app(AllocationCoverageService::class)->forYear($this->organization->id, 2026);
    }

    public function test_each_line_falls_into_one_category(): void
    {
        $this->entry('R-JOB', $this->bank, $this->revenue, '1000.00', $this->job);
        $this->entry('R-OPEN', $this->bank, $this->revenue, '50.00');
        $this->entry('E-OPEN', $this->otherExpense, $this->bank, '30.00');
        $this->entry('E-BLOCK', $this->expense, $this->bank, '20.00');
        $this->entry('E-SALARY', $this->salary, $this->bank, '4000.00');

        $block = BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Rent', 'kind' => 'running']);
        $block->accounts()->sync([$this->expense->id]);

        $coverage = $this->coverage();

        $this->assertSame(['count' => 1, 'amount' => '1000.00'], $coverage['revenue']['assigned']);
        $this->assertSame(['count' => 1, 'amount' => '50.00'], $coverage['revenue']['open']);
        $this->assertSame(['count' => 1, 'amount' => '30.00'], $coverage['expense']['open']);
        $this->assertSame(['count' => 1, 'amount' => '20.00'], $coverage['expense']['general']);
        $this->assertSame(['count' => 1, 'amount' => '4000.00'], $coverage['expense']['payroll']);
        $this->assertSame(2, $coverage['open']);
        $this->assertSame(2, $coverage['done']);
        $this->assertSame(4, $coverage['total']);
    }

    public function test_a_cost_block_of_another_year_does_not_count(): void
    {
        $this->entry('E-BLOCK', $this->expense, $this->bank, '20.00');
        $block = BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2025, 'name' => 'Rent', 'kind' => 'running']);
        $block->accounts()->sync([$this->expense->id]);

        $this->assertSame(1, $this->coverage()['expense']['open']['count']);
    }

    public function test_both_halves_of_a_reversal_are_left_out(): void
    {
        $original = $this->entry('R-WRONG', $this->bank, $this->revenue, '80.00');
        $ledger = app(LedgerService::class);
        $ledger->postDraft($ledger->reverseEntry($original, date: '2026-05-05'));

        $coverage = $this->coverage();

        $this->assertSame(0, $coverage['total']);
        $this->assertSame(2, $coverage['corrections']);
    }

    public function test_the_inbox_lists_only_open_lines_unless_general_ones_are_asked_for(): void
    {
        $this->entry('R-JOB', $this->bank, $this->revenue, '1000.00', $this->job);
        $this->entry('R-OPEN', $this->bank, $this->revenue, '50.00');
        $this->entry('E-BLOCK', $this->expense, $this->bank, '20.00');
        $this->entry('E-SALARY', $this->salary, $this->bank, '4000.00');
        BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Rent', 'kind' => 'running'])
            ->accounts()->sync([$this->expense->id]);

        $this->actAsOrg()->get('/projects/unallocated?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Unallocated')
                ->has('lines', 1)
                ->where('lines.0.reference', 'R-OPEN')
                ->where('lines.0.category', 'open')
                ->where('coverage.open', 1)
                ->has('blocks', 1));

        $this->actAsOrg()->get('/projects/unallocated?year=2026&general=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('lines', 2)
                ->where('lines.1.reference', 'E-BLOCK')
                ->where('lines.1.category', 'general'));
    }

    public function test_a_single_receipt_goes_to_the_internal_job_general(): void
    {
        $entry = $this->entry('R-FX', $this->bank, $this->revenue, '12.00');
        $line = $this->lineOn($entry, $this->revenue->id);

        $this->actAsOrg()->post('/projects/unallocated/general', ['line_id' => $line->id, 'scope' => 'line', 'year' => 2026])
            ->assertSessionHasNoErrors();

        $general = Project::query()->where('code', AllocationCoverageService::GENERAL_CODE)->sole();
        $this->assertSame(ProjectKind::Internal, $general->kind);
        $this->assertSame($general->id, $this->allocationsOf($line)[0]['project']);
        $this->assertSame(['count' => 1, 'amount' => '12.00'], $this->coverage()['revenue']['general']);

        // A second one reuses the same job.
        $other = $this->lineOn($this->entry('R-FX2', $this->bank, $this->revenue, '3.00'), $this->revenue->id);
        $this->actAsOrg()->post('/projects/unallocated/general', ['line_id' => $other->id, 'scope' => 'line', 'year' => 2026]);
        $this->assertSame(1, Project::query()->where('code', AllocationCoverageService::GENERAL_CODE)->count());
    }

    public function test_an_expense_account_goes_into_a_new_cost_block_named_after_it(): void
    {
        $first = $this->entry('E-1', $this->otherExpense, $this->bank, '30.00');
        $this->entry('E-2', $this->otherExpense, $this->bank, '45.00');

        $this->actAsOrg()->post('/projects/unallocated/general', [
            'line_id' => $this->lineOn($first, $this->otherExpense->id)->id,
            'scope' => 'account',
            'year' => 2026,
        ])->assertSessionHasNoErrors();

        $block = BudgetGroup::query()->where('name', '6500 Office')->sole();
        $this->assertSame([$this->otherExpense->id], $block->accounts()->pluck('accounts.id')->all());
        $this->assertSame(['count' => 2, 'amount' => '75.00'], $this->coverage()['expense']['general']);
        $this->assertSame(0, $this->coverage()['open']);
    }

    public function test_an_expense_account_joins_an_existing_cost_block(): void
    {
        $block = BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Office', 'kind' => 'running']);
        $block->accounts()->sync([$this->expense->id]);
        $entry = $this->entry('E-1', $this->otherExpense, $this->bank, '30.00');

        $this->actAsOrg()->post('/projects/unallocated/general', [
            'line_id' => $this->lineOn($entry, $this->otherExpense->id)->id,
            'scope' => 'account',
            'year' => 2026,
            'budget_group_id' => $block->id,
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$this->expense->id, $this->otherExpense->id], $block->accounts()->pluck('accounts.id')->all());
        $this->assertSame(1, BudgetGroup::query()->count());
    }

    public function test_a_revenue_account_cannot_go_into_a_cost_block(): void
    {
        $entry = $this->entry('R-1', $this->bank, $this->revenue, '30.00');

        $this->actAsOrg()->post('/projects/unallocated/general', [
            'line_id' => $this->lineOn($entry, $this->revenue->id)->id,
            'scope' => 'account',
            'year' => 2026,
        ])->assertSessionHasErrors('scope');

        $this->assertSame(0, BudgetGroup::query()->count());
    }

    public function test_a_line_of_another_organization_is_not_found(): void
    {
        $entry = $this->entry('R-1', $this->bank, $this->revenue, '30.00');
        $line = $this->lineOn($entry, $this->revenue->id);
        JournalEntry::query()->whereKey($entry->id)->update(['organization_id' => Organization::factory()->create()->id]);

        $this->actAsOrg()->post('/projects/unallocated/general', ['line_id' => $line->id, 'scope' => 'line', 'year' => 2026])
            ->assertNotFound();
    }

    public function test_the_job_list_shows_the_coverage_of_the_year(): void
    {
        $this->entry('R-OPEN', $this->bank, $this->revenue, '50.00');

        $this->actAsOrg()->get('/projects?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Index')
                ->where('coverage.open', 1)
                ->where('coverage.revenue.open.amount', '50.00'));
    }
}
