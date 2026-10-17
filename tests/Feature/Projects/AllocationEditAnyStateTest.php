<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Models\AllocationReview;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Allocations change at any time, whatever state the entry is in (spec 009,
 * User Story 3), and the journal form reads and writes them (User Story 2).
 */
class AllocationEditAnyStateTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->alpha = $this->makeProject('ALPHA');
        $this->beta = $this->makeProject('BETA');
    }

    public function test_an_entry_in_a_settled_vat_quarter_gets_an_allocation(): void
    {
        $entry = $this->postSimpleEntry();
        $this->lockVatQuarter('2026-07-01', '2026-09-30');

        $this->assertAllocatable($entry);
    }

    public function test_an_entry_in_a_closed_year_gets_an_allocation(): void
    {
        $entry = $this->postSimpleEntry();
        FiscalYear::where('organization_id', $this->organization->id)->update(['status' => FiscalYearStatus::Closed]);

        $this->assertAllocatable($entry);
    }

    public function test_an_archived_entry_gets_an_allocation_and_its_lines_stay_untouched(): void
    {
        $entry = $this->postSimpleEntry();
        $entry->update(['archived_at' => now()]);
        $before = DB::table('transaction_lines')->where('journal_entry_id', $entry->id)->orderBy('id')->get()->toArray();

        $this->assertAllocatable($entry);

        $after = DB::table('transaction_lines')->where('journal_entry_id', $entry->id)->orderBy('id')->get()->toArray();
        $this->assertEquals($before, $after);
        $this->assertNotNull($entry->fresh()->archived_at);
        $this->assertSame(1, $entry->fresh()->revision);
    }

    public function test_an_entry_from_a_strict_organization_gets_an_allocation(): void
    {
        $this->organization->update(['posted_entry_edit_mode' => 'strict']);
        $entry = $this->postSimpleEntry();

        $this->assertAllocatable($entry);
    }

    public function test_the_change_is_logged_with_old_and_new_state(): void
    {
        $entry = $this->postSimpleEntry();
        $line = $this->lineOn($entry, $this->expense->id);

        $this->putAllocations($entry, [['id' => $line->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 100]]]]);
        $this->putAllocations($entry, [['id' => $line->id, 'allocations' => [['project_id' => $this->beta->id, 'share' => 100]]]]);

        $log = Activity::query()->where('event', 'allocations_replaced')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($entry->id, $log->subject_id);
        $this->assertEquals($this->user->id, $log->causer_id);
        $this->assertSame($this->alpha->id, $log->properties['old'][$line->id][0]['project_id']);
        $this->assertSame($this->beta->id, $log->properties['attributes'][$line->id][0]['project_id']);
    }

    public function test_saving_resolves_an_open_review(): void
    {
        $entry = $this->postSimpleEntry();
        AllocationReview::create([
            'organization_id' => $this->organization->id,
            'journal_entry_id' => $entry->id,
            'reason' => AllocationReview::REASON_LINES_REBUILT,
            'previous' => [],
        ]);

        $this->putAllocations($entry, [['id' => $this->lineOn($entry, $this->expense->id)->id, 'allocations' => []]]);

        $this->assertNotNull(AllocationReview::sole()->resolved_at);
        $this->assertSame($this->user->id, AllocationReview::sole()->resolved_by);
    }

    public function test_a_review_can_be_dismissed(): void
    {
        $entry = $this->postSimpleEntry();
        $review = AllocationReview::create([
            'organization_id' => $this->organization->id,
            'journal_entry_id' => $entry->id,
            'reason' => AllocationReview::REASON_LINES_REBUILT,
            'previous' => [['account_id' => (string) $this->expense->id, 'debit' => '100.00', 'credit' => '0.00', 'allocations' => [['project_id' => $this->alpha->id, 'employee_id' => null, 'share' => '100.0000']]]],
        ]);

        $this->actAsOrg()->get('/projects/allocation-reviews')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/AllocationReviews')
                ->where('reviews.0.previous.0.allocations.0.project', 'ALPHA Project ALPHA'));

        $this->actAsOrg()->post("/projects/allocation-reviews/{$review->id}/dismiss")->assertSessionHasNoErrors();

        $this->assertNotNull($review->fresh()->resolved_at);
    }

    public function test_a_new_allocation_to_a_closed_project_is_refused_but_an_existing_one_is_kept(): void
    {
        $entry = $this->postSimpleEntry();
        $line = $this->lineOn($entry, $this->expense->id);
        $this->putAllocations($entry, [['id' => $line->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 100]]]]);
        $this->alpha->update(['is_active' => false]);
        $this->beta->update(['is_active' => false]);

        $this->putAllocations($entry, [['id' => $line->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 50]]]])
            ->assertSessionHasNoErrors();
        $this->putAllocations($entry, [['id' => $line->id, 'allocations' => [['project_id' => $this->beta->id, 'share' => 100]]]])
            ->assertSessionHasErrors('lines.0.allocations.0.project_id');
    }

    public function test_a_line_of_another_entry_is_refused(): void
    {
        $entry = $this->postSimpleEntry();
        $other = $this->postSimpleEntry(reference: 'JE-2');

        $this->putAllocations($entry, [['id' => $this->lineOn($other, $this->expense->id)->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 100]]]])
            ->assertSessionHasErrors('lines');
        $this->assertSame(0, LineAllocation::count());
    }

    public function test_bulk_allocation_covers_only_revenue_and_expense_lines(): void
    {
        $first = $this->postSimpleEntry();
        $second = $this->postSimpleEntry(reference: 'JE-2', debit: $this->bank, credit: $this->revenue);

        $this->actAsOrg()->post('/accounting/journal-entry-allocations/bulk', [
            'entry_ids' => [$first->id, $second->id],
            'project_id' => $this->alpha->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, LineAllocation::count());
        $this->assertSame([], $this->allocationsOf($this->lineOn($first, $this->bank->id)));
        $this->assertSame($this->alpha->id, $this->allocationsOf($this->lineOn($second, $this->revenue->id))[0]['project']);
        $this->assertSame(AllocationSource::Bulk, LineAllocation::query()->first()->source);
    }

    public function test_bulk_allocation_keeps_existing_ones_unless_told_to_overwrite(): void
    {
        $entry = $this->postSimpleEntry();
        $line = $this->lineOn($entry, $this->expense->id);
        $this->putAllocations($entry, [['id' => $line->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 40], ['project_id' => $this->beta->id, 'share' => 60]]]]);

        $this->actAsOrg()->post('/accounting/journal-entry-allocations/bulk', [
            'entry_ids' => [$entry->id],
            'project_id' => $this->beta->id,
        ])->assertSessionHas('warning');
        $this->assertCount(2, $this->allocationsOf($line));

        $this->actAsOrg()->post('/accounting/journal-entry-allocations/bulk', [
            'entry_ids' => [$entry->id],
            'project_id' => $this->beta->id,
            'overwrite' => true,
        ]);
        $this->assertSame([['project' => $this->beta->id, 'employee' => null, 'share' => '100.0000']], $this->allocationsOf($line));
    }

    public function test_a_user_without_the_allocate_permission_is_refused(): void
    {
        $entry = $this->postSimpleEntry();
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');

        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id])
            ->put("/accounting/journal-entries/{$entry->id}/allocations", [
                'lines' => [['id' => $this->lineOn($entry, $this->expense->id)->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 100]]]],
            ])->assertForbidden();
    }

    public function test_the_journal_form_creates_an_entry_with_a_split(): void
    {
        $this->actAsOrg()->post('/accounting/journal-entries', [
            'date' => '2026-08-10',
            'reference' => 'WEB-1',
            'description' => 'Split by form',
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '100.00', 'credit' => '0', 'allocations' => [
                    ['project_id' => $this->alpha->id, 'employee_id' => null, 'share' => '75'],
                    ['project_id' => $this->beta->id, 'employee_id' => null, 'share' => '25'],
                ]],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '100.00', 'allocations' => []],
            ],
        ])->assertSessionHasNoErrors();

        $entry = JournalEntry::query()->where('reference', 'WEB-1')->sole();
        $this->assertCount(2, $this->allocationsOf($this->lineOn($entry, $this->expense->id)));
    }

    public function test_the_journal_form_ignores_allocations_while_the_module_is_off(): void
    {
        $this->organization->update(['enabled_modules' => ['projects' => false]]);

        $this->actAsOrg()->post('/accounting/journal-entries', [
            'date' => '2026-08-10',
            'reference' => 'WEB-2',
            'description' => 'Module off',
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '100.00', 'credit' => '0', 'allocations' => [['project_id' => $this->alpha->id, 'share' => '100']]],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '100.00'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, LineAllocation::count());
        $this->actAsOrg()->get('/accounting/journal-entries')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('allocationOptions', null)
                ->missing('entries.data.0.lines.0.allocations'));
    }

    public function test_the_journal_shows_allocations_and_project_codes(): void
    {
        $entry = $this->postSimpleEntry();
        $this->putAllocations($entry, [['id' => $this->lineOn($entry, $this->expense->id)->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 100]]]]);

        $this->actAsOrg()->get('/accounting/journal-entries')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entries.data.0.cost_center', 'ALPHA')
                ->has('allocationOptions.projects', 2)
                ->where('entries.data.0.lines.0.allocations.0.project.code', 'ALPHA'));
    }

    public function test_an_entry_allocated_to_a_person_who_left_can_still_be_saved(): void
    {
        $employee = Employee::factory()->for($this->organization)->create();
        $entry = $this->postSimpleEntry();
        $this->putAllocations($entry, [['id' => $this->lineOn($entry, $this->expense->id)->id, 'allocations' => [['employee_id' => $employee->id, 'share' => 100]]]])
            ->assertSessionHasNoErrors();
        $employee->delete();

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-08-10',
            'reference' => 'JE-1',
            'description' => 'Still savable',
            'revision' => 1,
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '100.00', 'credit' => '0', 'allocations' => [['project_id' => null, 'employee_id' => $employee->id, 'share' => '100']]],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '100.00', 'allocations' => []],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame($employee->id, $this->allocationsOf($this->lineOn($entry->fresh(), $this->expense->id))[0]['employee']);
    }

    public function test_a_user_who_may_not_allocate_cannot_fail_a_save_over_allocations(): void
    {
        $member = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($member->id, ['role' => 'member']);
        $this->assignOrganizationRole($member, $this->organization, 'member');

        $this->actingAs($member)->withSession(['current_organization_id' => $this->organization->id])
            ->post('/accounting/journal-entries', [
                'date' => '2026-08-10',
                'reference' => 'MEMBER-1',
                'description' => 'Member entry',
                'lines' => [
                    ['account_id' => $this->expense->id, 'debit' => '10.00', 'credit' => '0', 'allocations' => [['project_id' => 999999, 'share' => '100']]],
                    ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '10.00'],
                ],
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, JournalEntry::query()->where('reference', 'MEMBER-1')->count());
        $this->assertSame(0, LineAllocation::count());
    }

    public function test_the_list_keeps_a_mixed_cost_centre_empty_where_projects_are_off(): void
    {
        $entry = $this->postSimpleEntry();
        $this->putAllocations($entry, [
            ['id' => $this->lineOn($entry, $this->expense->id)->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 100]]],
            ['id' => $this->lineOn($entry, $this->bank->id)->id, 'allocations' => [['project_id' => $this->beta->id, 'share' => 100]]],
        ]);

        $this->actAsOrg()->get('/accounting/journal-entries')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('entries.data.0.cost_center', 'ALPHA, BETA'));

        $this->organization->update(['enabled_modules' => ['projects' => false]]);
        $this->actAsOrg()->get('/accounting/journal-entries')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('entries.data.0.cost_center', null));
    }

    private function assertAllocatable(JournalEntry $entry): void
    {
        $line = $this->lineOn($entry, $this->expense->id);

        $this->putAllocations($entry, [['id' => $line->id, 'allocations' => [['project_id' => $this->alpha->id, 'share' => 100]]]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame($this->alpha->id, $this->allocationsOf($line)[0]['project']);
        $this->assertSame(1, TransactionLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $this->expense->id)->count());
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function putAllocations(JournalEntry $entry, array $lines): TestResponse
    {
        return $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}/allocations", ['lines' => $lines]);
    }
}
