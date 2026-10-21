<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectShare;
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
 * The page of a job, codes picked by Gäld and what each job brought each
 * person (spec 010, US3 to US5).
 */
class JobPageTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $anna;

    private Employee $ben;

    private Project $group;

    private Project $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->anna = Employee::factory()->for($this->organization)->create(['first_name' => 'Anna', 'last_name' => 'A']);
        $this->ben = Employee::factory()->for($this->organization)->create(['first_name' => 'Ben', 'last_name' => 'B']);

        $this->group = $this->makeProject('B-AI', kind: ProjectKind::Area);
        $this->group->update(['name' => 'AI courses']);
        $this->job = $this->makeProject('P26-01', $this->group);
        $this->job->update(['name' => 'Course city', 'status' => ProjectStatus::Done]);
        foreach (['anna' => '25', 'ben' => '75'] as $who => $share) {
            ProjectShare::create(['organization_id' => $this->organization->id, 'cost_center_id' => $this->job->id, 'employee_id' => $this->{$who}->id, 'share' => $share]);
        }

        $ledger = app(LedgerService::class);
        $ledger->postEntry($this->organization->id, new JournalEntryData('2026-04-01', 'REV-1', 'Payment city', [
            new JournalLineData(accountId: (string) $this->bank->id, debit: '1000.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->revenue->id, debit: '0', credit: '1000.00', allocations: $this->whole($this->job)),
        ]));
        $ledger->postEntry($this->organization->id, new JournalEntryData('2026-04-02', 'EXP-1', 'Trainer', [
            new JournalLineData(accountId: (string) $this->otherExpense->id, debit: '200.00', credit: '0', allocations: $this->whole($this->job)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '200.00'),
        ]));
    }

    public function test_the_job_page_shows_figures_receipts_and_net_per_person(): void
    {
        $this->actAsOrg()->get("/projects/{$this->job->id}/report?from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Report')
                ->where('project.name', 'Course city')
                ->where('project.parent.name', 'AI courses')
                ->where('report.total_revenue', '1000.00')
                ->where('report.total_expenses', '200.00')
                ->where('report.contribution', '800.00')
                ->where('value', '800.00')
                ->has('report.lines', 2)
                ->where('report.lines.0.reference', 'REV-1')
                ->where('report.lines.0.side', 'revenue')
                ->where('report.lines.1.description', 'Trainer')
                ->where('report.lines.1.side', 'expenses')
                ->has('split', 2)
                ->where('split.0.name', 'Ben B')
                ->where('split.0.part', '600.00')
                ->where('split.1.part', '200.00')
                ->where('can.steer', true));
    }

    public function test_a_job_without_own_key_takes_the_key_of_its_project(): void
    {
        ProjectShare::create(['organization_id' => $this->organization->id, 'cost_center_id' => $this->group->id, 'employee_id' => $this->anna->id, 'share' => '100']);
        $other = $this->makeProject('P26-02', $this->group);
        $other->update(['expected_amount' => '300.00', 'status' => ProjectStatus::InProgress]);

        $this->actAsOrg()->get("/projects/{$other->id}/report?from=2026-01-01&to=2026-12-31")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('value', Carbon::now()->year === 2026 ? '300.00' : '0.00')
                ->where('split.0.name', 'Anna A')
                ->where('split.0.inherited', true));
    }

    public function test_the_page_of_a_project_lists_its_jobs(): void
    {
        $this->actAsOrg()->get("/projects/{$this->group->id}/report?from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.contribution', '800.00')
                ->where('report.projects.1.name', 'Course city')
                ->where('split', []));
    }

    public function test_a_viewer_sees_the_job_but_not_who_has_which_share(): void
    {
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');

        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id])
            ->get("/projects/{$this->job->id}/report")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('split', [])
                ->missing('project.shares')
                ->where('can.steer', false));
    }

    public function test_a_new_job_without_code_gets_the_next_free_one(): void
    {
        $prefix = 'P'.now()->format('y').'-';
        $this->makeProject($prefix.'07');

        $this->actAsOrg()->post('/projects', ['name' => 'Workshop', 'kind' => 'project', 'status' => 'possible', 'code' => ''])
            ->assertSessionHasNoErrors();
        $this->actAsOrg()->post('/projects', ['name' => 'Workshop 2', 'kind' => 'project', 'status' => 'possible']);

        $this->assertSame($prefix.'08', Project::query()->where('name', 'Workshop')->sole()->code);
        $this->assertSame($prefix.'09', Project::query()->where('name', 'Workshop 2')->sole()->code);
    }

    public function test_a_new_project_without_code_is_named_after_it(): void
    {
        $this->actAsOrg()->post('/projects', ['name' => 'Kurse für Lehrer', 'kind' => 'area', 'status' => 'in_progress'])
            ->assertSessionHasNoErrors();
        $this->actAsOrg()->post('/projects', ['name' => 'Kurse für Lehrer', 'kind' => 'area', 'status' => 'in_progress']);

        $this->assertEqualsCanonicalizing(
            ['B-KURSE-FUR-LEHRER', 'B-KURSE-FUR-LEHRER-2'],
            Project::query()->where('name', 'Kurse für Lehrer')->pluck('code')->all(),
        );
    }

    public function test_an_edit_without_code_keeps_the_code(): void
    {
        $this->actAsOrg()->put("/projects/{$this->job->id}", ['name' => 'Renamed', 'kind' => 'project', 'status' => 'done', 'code' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('P26-01', $this->job->fresh()->code);
    }

    public function test_deleting_from_the_job_page_returns_to_the_list(): void
    {
        $empty = $this->makeProject('EMPTY');

        $this->actAsOrg()->delete("/projects/{$empty->id}", ['to_index' => 1])
            ->assertRedirect(route('projects.index'));
    }

    public function test_the_people_page_breaks_the_net_down_by_job(): void
    {
        $steering = app(SteeringService::class)->forYear($this->organization->id, 2026);
        $ben = collect($steering['people'])->firstWhere('employee_id', $this->ben->id);

        $this->assertSame('600.00', $ben['contribution']);
        $this->assertCount(1, $ben['jobs']);
        $this->assertSame([
            'open_part' => '0.00',
            'rest_part' => '0.00',
            'id' => $this->job->id,
            'code' => 'P26-01',
            'name' => 'Course city',
            'group' => 'AI courses',
            'status' => 'done',
            'booked' => true,
            'billing' => null,
            'share' => '75',
            'value' => '800.00',
            'part' => '600.00',
        ], $ben['jobs'][0]);
    }
}
