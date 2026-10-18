<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The revenue and expense lines of a year as a journal with project, job
 * and people on every line (spec 010).
 */
class ProjectJournalTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Project $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->job = $this->makeProject('P26-01');
    }

    private function entry(string $reference, Account $debit, Account $credit, string $amount, ?Project $project = null): JournalEntry
    {
        $allocate = fn (Account $account) => $project !== null && $account->id !== $this->bank->id ? $this->whole($project) : null;

        return app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-05-04', $reference, "Text {$reference}", [
            new JournalLineData(accountId: (string) $debit->id, debit: $amount, credit: '0', allocations: $allocate($debit)),
            new JournalLineData(accountId: (string) $credit->id, debit: '0', credit: $amount, allocations: $allocate($credit)),
        ]));
    }

    public function test_each_line_shows_its_accounts_and_its_place(): void
    {
        $this->entry('R-JOB', $this->bank, $this->revenue, '1000.00', $this->job);
        $this->entry('E-BLOCK', $this->expense, $this->bank, '20.00');
        $this->entry('E-OPEN', $this->otherExpense, $this->bank, '30.00');
        $block = BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Rent', 'kind' => 'running']);
        $block->accounts()->sync([$this->expense->id]);

        $this->actAsOrg()->get('/projects/journal?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Journal')
                ->has('rows', 3)
                ->where('rows.0.reference', 'R-JOB')
                ->where('rows.0.debit_account', $this->bank->code)
                ->where('rows.0.credit_account', $this->revenue->code)
                ->where('rows.0.net', '1000.00')
                ->where('rows.0.category', 'assigned')
                ->where('rows.0.allocations.0.project_id', $this->job->id)
                ->where('rows.1.reference', 'E-BLOCK')
                ->where('rows.1.debit_account', $this->expense->code)
                ->where('rows.1.net', '-20.00')
                ->where('rows.1.category', 'general')
                ->where('rows.1.block', 'Rent')
                ->where('rows.2.category', 'open')
                ->where('can.allocate', true));
    }

    public function test_a_general_line_moves_to_a_project_before_a_job_is_chosen(): void
    {
        $area = $this->makeProject('B-LAB', kind: ProjectKind::Area);
        $entry = $this->entry('E-BLOCK', $this->expense, $this->bank, '20.00');
        $block = BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Rent', 'kind' => 'running']);
        $block->accounts()->sync([$this->expense->id]);
        $line = $entry->lines()->where('account_id', $this->expense->id)->firstOrFail();

        $this->actAsOrg()->post('/projects/unallocated', ['items' => [['line_id' => $line->id, 'project_id' => $area->id]]])
            ->assertSessionHasNoErrors();

        $this->actAsOrg()->get('/projects/journal?year=2026')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.category', 'assigned')
                ->where('rows.0.allocations.0.project_id', $area->id));
    }

    public function test_a_viewer_reads_the_journal_without_editing_or_shares(): void
    {
        $this->entry('R-JOB', $this->bank, $this->revenue, '1000.00', $this->job);
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');

        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id])
            ->get('/projects/journal?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)
                ->where('can.allocate', false)
                ->where('can.steer', false)
                ->missing('projects.0.shares'));
    }
}
