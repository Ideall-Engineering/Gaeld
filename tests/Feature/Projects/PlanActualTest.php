<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Services\PlanActualService;
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

        $this->actAsOrg()->get('/projects/plan?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/Plan')->where('plan.groups.0.name', 'Software & SaaS'));
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

        $this->actingAs($viewer)->withSession($session)->get('/projects/plan')->assertOk();
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
    private function row(string $asOf, BudgetGroup $group): array
    {
        $plan = app(PlanActualService::class)->forYear($this->organization->id, 2026, Carbon::parse($asOf));

        return collect($plan['groups'])->firstWhere('id', $group->id);
    }
}
