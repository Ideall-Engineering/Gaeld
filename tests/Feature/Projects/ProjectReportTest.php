<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\ProjectReportService;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Revenue, expenses and contribution per project (spec 009, User Story 5).
 */
class ProjectReportTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Project $area;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->area = $this->makeProject('AREA', kind: ProjectKind::Area);
        $this->alpha = $this->makeProject('ALPHA', $this->area);
        $this->beta = $this->makeProject('BETA', $this->area);
    }

    public function test_an_area_rolls_up_its_projects(): void
    {
        $this->book('JE-1', $this->bank, $this->revenue, '1000.00', revenueTo: $this->whole($this->alpha));
        $this->book('JE-2', $this->expense, $this->bank, '300.00', expenseTo: $this->whole($this->beta));

        $report = $this->report($this->area);

        $this->assertSame('1000.00', $report['total_revenue']);
        $this->assertSame('300.00', $report['total_expenses']);
        $this->assertSame('700.00', $report['contribution']);
        $byCode = array_column($report['projects'], null, 'code');
        $this->assertSame('1000.00', $byCode['ALPHA']['contribution']);
        $this->assertSame('-300.00', $byCode['BETA']['contribution']);
        $this->assertSame('0.00', $byCode['AREA']['contribution']);
    }

    public function test_a_single_project_sees_only_its_share(): void
    {
        $this->book('JE-1', $this->expense, $this->bank, '100.00', expenseTo: [
            new LineAllocationData(projectId: $this->alpha->id, employeeId: null, share: '33.3333'),
            new LineAllocationData(projectId: $this->beta->id, employeeId: null, share: '66.6667'),
        ]);

        $this->assertSame('33.33', $this->report($this->alpha)['total_expenses']);
        $this->assertSame('66.67', $this->report($this->beta)['total_expenses']);
        $this->assertSame('100.00', $this->report($this->area)['total_expenses'], 'The rounding must not lose a cent.');
    }

    public function test_a_reversal_nets_the_project_to_zero(): void
    {
        $entry = $this->book('JE-1', $this->expense, $this->bank, '250.00', expenseTo: $this->whole($this->alpha));
        app(LedgerService::class)->postDraft(app(LedgerService::class)->reverseEntry($entry, date: '2026-08-11'));

        $this->assertSame('0.00', $this->report($this->alpha)['total_expenses']);
    }

    public function test_drafts_closing_entries_and_other_periods_are_left_out(): void
    {
        $this->book('JE-1', $this->expense, $this->bank, '100.00', expenseTo: $this->whole($this->alpha));
        $this->book('JE-2', $this->expense, $this->bank, '50.00', expenseTo: $this->whole($this->alpha), date: '2025-12-31');
        $closing = $this->book('CLOSE', $this->expense, $this->bank, '70.00', expenseTo: $this->whole($this->alpha));
        $closing->update(['type' => 'year_end_closing']);
        app(LedgerService::class)->createDraft($this->organization->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'DRAFT',
            description: 'Draft',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '40.00', credit: '0', allocations: $this->whole($this->alpha)),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '40.00'),
            ],
        ));

        $this->assertSame('100.00', $this->report($this->alpha)['total_expenses']);
    }

    public function test_balance_sheet_lines_are_not_counted(): void
    {
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'JE-1',
            description: 'x',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '80.00', credit: '0', allocations: $this->whole($this->alpha)),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '80.00', allocations: $this->whole($this->alpha)),
            ],
        ));

        $report = $this->report($this->alpha);
        $this->assertSame('80.00', $report['total_expenses']);
        $this->assertSame('0.00', $report['total_revenue']);
    }

    public function test_the_page_and_exports_answer(): void
    {
        $this->book('JE-1', $this->expense, $this->bank, '100.00', expenseTo: $this->whole($this->alpha));

        $this->actAsOrg()->get("/projects/{$this->area->id}/report?from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Report')
                ->where('report.total_expenses', '100.00'));

        $csv = $this->actAsOrg()->get("/projects/{$this->area->id}/report/export/csv?from=2026-01-01&to=2026-12-31");
        $csv->assertOk();
        $this->assertStringContainsString('100.00', $csv->streamedContent());

        $this->actAsOrg()->get("/projects/{$this->area->id}/report/export/pdf?from=2026-01-01&to=2026-12-31")->assertOk();
    }

    public function test_an_employee_may_not_see_the_report(): void
    {
        $employee = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($employee->id, ['role' => 'employee']);
        $this->assignOrganizationRole($employee, $this->organization, 'employee');

        $this->actingAs($employee)->withSession(['current_organization_id' => $this->organization->id])
            ->get("/projects/{$this->area->id}/report")
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function report(Project $project): array
    {
        return app(ProjectReportService::class)->forProject($project, '2026-01-01', '2026-12-31');
    }

    /**
     * @param  array<int, LineAllocationData>|null  $revenueTo
     * @param  array<int, LineAllocationData>|null  $expenseTo
     */
    private function book(
        string $reference,
        Account $debit,
        Account $credit,
        string $amount,
        ?array $revenueTo = null,
        ?array $expenseTo = null,
        string $date = '2026-08-10',
    ): JournalEntry {
        $allocationsFor = fn ($account) => match ($account->id) {
            $this->revenue->id => $revenueTo,
            $this->expense->id => $expenseTo,
            default => null,
        };

        return app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: $date,
            reference: $reference,
            description: 'x',
            lines: [
                new JournalLineData(accountId: (string) $debit->id, debit: $amount, credit: '0', allocations: $allocationsFor($debit)),
                new JournalLineData(accountId: (string) $credit->id, debit: '0', credit: $amount, allocations: $allocationsFor($credit)),
            ],
        ));
    }
}
