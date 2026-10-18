<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Keeping projects and areas (spec 009, User Story 1).
 */
class ProjectManagementTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->enableProjects();
    }

    public function test_a_project_is_created_with_all_its_fields(): void
    {
        $area = $this->makeProject('AREA', kind: ProjectKind::Area);
        $contact = Contact::factory()->create(['organization_id' => $this->organization->id]);
        $employee = Employee::factory()->for($this->organization)->create();

        $this->actAsOrg()->post('/projects', [
            'code' => 'p-1',
            'name' => 'Website',
            'parent_id' => $area->id,
            'kind' => 'project',
            'status' => 'possible',
            'contact_id' => $contact->id,
            'responsible_employee_id' => $employee->id,
            'target_date' => '2026-12-15',
            'expected_amount' => '12500.50',
            'notes' => 'First phase',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $project = Project::query()->where('code', 'P-1')->sole();
        $this->assertSame($area->id, $project->parent_id);
        $this->assertSame(ProjectStatus::Possible, $project->status);
        $this->assertSame($contact->id, $project->contact_id);
        $this->assertSame($employee->id, $project->responsible_employee_id);
        $this->assertSame('2026-12-15', $project->target_date->toDateString());
        $this->assertSame('12500.50', $project->expected_amount);
        $this->assertTrue($project->is_active);
    }

    public function test_the_list_shows_projects_below_their_area(): void
    {
        $area = $this->makeProject('A', kind: ProjectKind::Area);
        $this->makeProject('B-CHILD', $area);
        $this->makeProject('AA-TOP');

        $this->actAsOrg()->get('/projects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Index')
                ->where('projects.0.code', 'A')
                ->where('projects.1.code', 'B-CHILD')
                ->where('projects.1.depth', 1)
                ->where('projects.2.code', 'AA-TOP')
                ->where('can.manage', true));
    }

    public function test_the_status_changes(): void
    {
        $project = $this->makeProject('P');

        $this->actAsOrg()->put("/projects/{$project->id}", [
            'code' => 'P',
            'name' => 'Renamed',
            'kind' => 'project',
            'status' => 'done',
            'is_active' => false,
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(ProjectStatus::Done, $project->status);
        $this->assertSame('Renamed', $project->name);
        $this->assertFalse($project->is_active);
    }

    public function test_a_project_cannot_become_its_own_ancestor(): void
    {
        $parent = $this->makeProject('PARENT');
        $child = $this->makeProject('CHILD', $parent);

        $this->actAsOrg()->put("/projects/{$parent->id}", [
            'code' => 'PARENT',
            'name' => 'Parent',
            'parent_id' => $child->id,
            'kind' => 'project',
            'status' => 'in_progress',
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_a_project_with_allocations_is_not_deleted(): void
    {
        $this->setUpPostedEntryFixtures();
        $project = $this->makeProject('USED');
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'JE-1',
            description: 'x',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '10.00', credit: '0', allocations: $this->whole($project)),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '10.00'),
            ],
        ));

        $this->actAsOrg()->delete("/projects/{$project->id}")->assertSessionHasErrors('project');
        $this->assertNotNull($project->fresh());
    }

    public function test_an_unused_project_is_deleted(): void
    {
        $project = $this->makeProject('FREE');

        $this->actAsOrg()->delete("/projects/{$project->id}")->assertSessionHasNoErrors();

        $this->assertNull($project->fresh());
    }

    public function test_the_pages_are_closed_while_the_module_is_off(): void
    {
        $this->organization->update(['enabled_modules' => ['projects' => false]]);

        $this->actAsOrg()->get('/projects')->assertForbidden();
        $this->actAsOrg()->post('/projects', ['code' => 'X', 'name' => 'X', 'kind' => 'project', 'status' => 'possible'])->assertForbidden();
    }

    public function test_the_module_is_off_for_an_organization_that_never_chose(): void
    {
        $this->organization->update(['enabled_modules' => null]);

        $this->actAsOrg()->get('/projects')->assertForbidden();
    }

    public function test_a_viewer_sees_projects_but_cannot_change_them(): void
    {
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');

        $session = ['current_organization_id' => $this->organization->id];

        $this->actingAs($viewer)->withSession($session)->get('/projects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manage', false));
        $this->actingAs($viewer)->withSession($session)
            ->post('/projects', ['code' => 'X', 'name' => 'X', 'kind' => 'project', 'status' => 'possible'])
            ->assertForbidden();
    }

    public function test_an_employee_does_not_see_projects(): void
    {
        $employee = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($employee->id, ['role' => 'employee']);
        $this->assignOrganizationRole($employee, $this->organization, 'employee');

        $this->actingAs($employee)->withSession(['current_organization_id' => $this->organization->id])
            ->get('/projects')
            ->assertForbidden();
    }

    public function test_a_project_of_another_organization_is_not_found(): void
    {
        $other = Organization::factory()->create();
        $foreign = Project::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
        ]);

        $this->actAsOrg()->delete("/projects/{$foreign->id}")->assertNotFound();
        $this->assertNotNull(Project::withoutGlobalScopes()->find($foreign->id));
    }
}
