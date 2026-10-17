<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\ProjectShare;
use App\Domains\Projects\Models\SteeringItem;
use App\Domains\Projects\Services\AcquisitionService;
use App\Domains\Projects\Services\SteeringService;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Contribution, drawings and what is left per person (spec 009, stage 5),
 * on a worked example.
 */
class SteeringTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $anna;

    private Employee $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->anna = Employee::factory()->for($this->organization)->create(['first_name' => 'Anna', 'last_name' => 'A']);
        $this->ben = Employee::factory()->for($this->organization)->create(['first_name' => 'Ben', 'last_name' => 'B']);

        $area = $this->makeProject('AREA', kind: ProjectKind::Area);
        $this->share($area->id, ['anna' => '50', 'ben' => '50']);

        // A: done, booked revenue 1000 and costs 200 -> value 800, area key
        $a = $this->makeProject('A', $area);
        $a->update(['status' => ProjectStatus::Done, 'expected_amount' => '5000.00']);
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-03-01', 'A-REV', 'x', [
            new JournalLineData(accountId: (string) $this->bank->id, debit: '1000.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->revenue->id, debit: '0', credit: '1000.00', allocations: $this->whole($a)),
        ]));
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-03-02', 'A-COST', 'x', [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '200.00', credit: '0', allocations: $this->whole($a)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '200.00'),
        ]));

        // B: possible, expected 500, area key
        $this->makeProject('B', $area)->update(['status' => ProjectStatus::Possible, 'expected_amount' => '500.00']);

        // C: in progress, expected 300, all Anna's
        $c = $this->makeProject('C', $area);
        $c->update(['status' => ProjectStatus::InProgress, 'expected_amount' => '300.00']);
        $this->share($c->id, ['anna' => '100']);

        // Lost projects count for nobody
        $this->makeProject('L', $area)->update(['status' => ProjectStatus::Lost, 'expected_amount' => '9999.00']);

        // Cost block of 1000: Anna did the work, bears 30 %, Ben 70 %
        $block = BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Rent', 'kind' => 'running', 'plan_amount' => '1000.00']);
        $block->people()->sync([
            $this->anna->id => ['carry_share' => '30', 'credit_share' => '100'],
            $this->ben->id => ['carry_share' => '70', 'credit_share' => '0'],
        ]);

        SteeringItem::create(['organization_id' => $this->organization->id, 'year' => 2026, 'employee_id' => $this->anna->id, 'label' => 'Salary', 'amount' => '2000.00']);
        app(AcquisitionService::class)->save(null, $this->organization->id, [
            'item' => 'Keyboard', 'purchased_on' => '2026-05-05', 'quantity' => '1', 'unit_price' => '100', 'status' => 'delivered',
        ], [['employee_id' => $this->ben->id, 'share' => '100']]);
    }

    public function test_the_worked_example(): void
    {
        $data = app(SteeringService::class)->forYear($this->organization->id, 2026);
        $people = collect($data['people'])->keyBy('name');
        $anna = $people['Anna A'];
        $ben = $people['Ben B'];

        $this->assertSame('400.00', $anna['by_status']['done']);
        $this->assertSame('250.00', $ben['by_status']['possible']);
        $this->assertSame('300.00', $anna['by_status']['in_progress']);
        $this->assertSame('0.00', $ben['by_status']['in_progress']);

        $this->assertSame('950.00', $anna['contribution']);
        $this->assertSame('650.00', $ben['contribution']);
        $this->assertSame('700.00', $anna['effect']);
        $this->assertSame('-700.00', $ben['effect']);
        $this->assertSame('1650.00', $anna['value']);
        $this->assertSame('-50.00', $ben['value']);
        $this->assertSame('2000.00', $anna['drawn']);
        $this->assertSame('100.00', $ben['drawn']);
        $this->assertSame('-350.00', $anna['play']);
        $this->assertSame('-150.00', $ben['play']);
        $this->assertSame('-600.00', $anna['play_without_possible']);
        $this->assertSame('-400.00', $ben['play_without_possible']);
        $this->assertSame('-500.00', $data['totals']['play']);
    }

    public function test_a_block_without_a_key_is_borne_equally_and_credits_nobody(): void
    {
        BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Software', 'kind' => 'running', 'plan_amount' => '200.00']);

        $people = collect(app(SteeringService::class)->forYear($this->organization->id, 2026)['people'])->keyBy('name');

        $this->assertSame('600.00', $people['Anna A']['effect']);
        $this->assertSame('-800.00', $people['Ben B']['effect']);
    }

    public function test_a_project_without_any_key_is_reported_as_unshared(): void
    {
        $this->makeProject('LOOSE')->update(['status' => ProjectStatus::Pipeline, 'expected_amount' => '123.00']);

        $this->assertSame('123.00', app(SteeringService::class)->forYear($this->organization->id, 2026)['unshared']);
    }

    public function test_the_page_and_exports_are_for_owners_only(): void
    {
        $this->actAsOrg()->get('/projects/steering?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Steering')->has('steering.people', 2));
        $this->assertStringContainsString('Anna A', $this->actAsOrg()->get('/projects/steering/export/csv?year=2026')->streamedContent());
        $this->actAsOrg()->get('/projects/steering/export/pdf?year=2026')->assertOk();

        $admin = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($admin->id, ['role' => 'admin']);
        $this->assignOrganizationRole($admin, $this->organization, 'admin');
        $this->actingAs($admin)->withSession(['current_organization_id' => $this->organization->id])
            ->get('/projects/steering')
            ->assertForbidden();
    }

    public function test_plan_items_are_added_and_removed(): void
    {
        $this->actAsOrg()->post('/projects/steering/items', [
            'year' => 2026, 'employee_id' => $this->ben->id, 'label' => '13th salary', 'amount' => '3076.93',
        ])->assertSessionHasNoErrors();

        $item = SteeringItem::query()->where('label', '13th salary')->sole();
        $this->actAsOrg()->delete("/projects/steering/items/{$item->id}")->assertSessionHasNoErrors();
        $this->assertNull($item->fresh());
    }

    public function test_keys_are_set_on_projects_and_blocks_by_owners(): void
    {
        $project = $this->makeProject('NEWKEY');

        $this->actAsOrg()->put("/projects/{$project->id}", [
            'code' => 'NEWKEY', 'name' => 'New', 'kind' => 'project', 'status' => 'pipeline',
            'shares' => [['employee_id' => $this->anna->id, 'share' => '25'], ['employee_id' => $this->ben->id, 'share' => '75']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['25.0000', '75.0000'], ProjectShare::query()->where('cost_center_id', $project->id)->orderBy('share')->pluck('share')->map(fn ($s) => (string) $s)->all());

        $block = BudgetGroup::query()->where('name', 'Rent')->sole();
        $this->actAsOrg()->put("/projects/plan/{$block->id}", [
            'name' => 'Rent', 'kind' => 'running', 'plan_amount' => '1000',
            'people' => [['employee_id' => $this->anna->id, 'carry_share' => '50', 'credit_share' => '0'], ['employee_id' => $this->ben->id, 'carry_share' => '50', 'credit_share' => '100']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('100.0000', (string) $block->people()->where('employees.id', $this->ben->id)->first()->pivot->credit_share);
    }

    /** @param  array<string, string>  $shares */
    private function share(int $projectId, array $shares): void
    {
        foreach ($shares as $who => $share) {
            ProjectShare::create([
                'organization_id' => $this->organization->id,
                'cost_center_id' => $projectId,
                'employee_id' => $who === 'anna' ? $this->anna->id : $this->ben->id,
                'share' => $share,
            ]);
        }
    }
}
