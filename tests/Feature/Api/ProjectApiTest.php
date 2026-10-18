<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\Security\SecurityTestCase;

/**
 * Projects and allocations over the API (spec 009, User Story 6).
 */
class ProjectApiTest extends SecurityTestCase
{
    private string $tokenA;

    private Account $bank;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        $this->orgA->update(['enabled_modules' => ['projects' => true]]);
        $this->bank = Account::create(['organization_id' => $this->orgA->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $this->expense = Account::create(['organization_id' => $this->orgA->id, 'code' => '6000', 'name' => 'Rent', 'type' => AccountType::Expense->value]);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
    }

    public function test_a_project_is_created_listed_and_changed(): void
    {
        $this->api()->withHeaders(['Idempotency-Key' => 'p-area'])
            ->postJson('/api/v1/projects', ['code' => 'area', 'name' => 'Area', 'kind' => 'area'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'AREA');

        $created = $this->api()->withHeaders(['Idempotency-Key' => 'p-web'])
            ->postJson('/api/v1/projects', [
                'code' => 'WEB',
                'name' => 'Website',
                'parent_code' => 'AREA',
                'status' => 'possible',
                'expected_amount' => '5000.00',
                'target_date' => '2026-12-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.parent_code', 'AREA')
            ->assertJsonPath('data.status', 'possible')
            ->assertJsonPath('data.kind', 'project');

        $id = $created->json('data.id');

        $this->api()->withHeaders(['Idempotency-Key' => 'p-web-done'])
            ->patchJson("/api/v1/projects/{$id}", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.name', 'Website');

        $this->api()->getJson('/api/v1/projects')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_entries_carry_allocations_and_accept_them(): void
    {
        $project = Project::create(['organization_id' => $this->orgA->id, 'code' => 'ALPHA', 'name' => 'Alpha']);

        $response = $this->api()->withHeaders(['Idempotency-Key' => 'je-1'])->postJson('/api/v1/journal-entries', [
            'date' => '2026-08-10',
            'reference' => 'API-1',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '6000', 'debit' => '100.00', 'credit' => '0', 'allocations' => [['project_code' => 'ALPHA', 'share' => '100']]],
                ['account_code' => '1020', 'debit' => '0', 'credit' => '100.00'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.lines.0.allocations.0.project_code', 'ALPHA')
            ->assertJsonPath('data.lines.1.allocations', []);
        $this->assertSame($project->id, LineAllocation::sole()->cost_center_id);
        $this->assertSame('api', LineAllocation::sole()->source->value);
    }

    public function test_allocations_of_a_locked_entry_are_replaced_and_replays_are_stable(): void
    {
        Project::create(['organization_id' => $this->orgA->id, 'code' => 'ALPHA', 'name' => 'Alpha']);
        $entry = $this->postEntry();
        $entry->update(['archived_at' => now()]);
        $lineId = DB::table('transaction_lines')->where('journal_entry_id', $entry->id)->where('account_id', $this->expense->id)->value('id');

        $payload = ['lines' => [['line_id' => $lineId, 'allocations' => [['project_code' => 'ALPHA', 'share' => '60']]]]];

        $first = $this->api()->withHeaders(['Idempotency-Key' => 'alloc-1'])
            ->putJson("/api/v1/journal-entries/{$entry->id}/allocations", $payload)
            ->assertOk()
            ->assertJsonPath('data.revision', 1);
        $second = $this->api()->withHeaders(['Idempotency-Key' => 'alloc-1'])
            ->putJson("/api/v1/journal-entries/{$entry->id}/allocations", $payload)
            ->assertOk();

        $this->assertEquals($first->json(), $second->json());
        $this->assertSame(1, LineAllocation::count());
        $this->assertSame('60.0000', (string) LineAllocation::sole()->share);
    }

    public function test_allocations_need_an_idempotency_key(): void
    {
        $entry = $this->postEntry();

        $this->api()->putJson("/api/v1/journal-entries/{$entry->id}/allocations", ['lines' => [['line_id' => 1, 'allocations' => []]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_key_required');
    }

    public function test_the_report_answers(): void
    {
        $project = Project::create(['organization_id' => $this->orgA->id, 'code' => 'ALPHA', 'name' => 'Alpha']);
        $this->api()->withHeaders(['Idempotency-Key' => 'je-r'])->postJson('/api/v1/journal-entries', [
            'date' => '2026-08-10',
            'reference' => 'API-R',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '6000', 'debit' => '40.00', 'credit' => '0', 'allocations' => [['project_code' => 'ALPHA']]],
                ['account_code' => '1020', 'debit' => '0', 'credit' => '40.00'],
            ],
        ])->assertCreated();

        $this->api()->getJson("/api/v1/projects/{$project->id}/report?from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertJsonPath('data.total_expenses', '40.00')
            // The line list of the job page (spec 010) stays out of the API.
            ->assertJsonMissingPath('data.lines');
    }

    public function test_nothing_changes_while_the_module_is_off(): void
    {
        $this->orgA->update(['enabled_modules' => ['projects' => false]]);
        $entry = $this->postEntry();

        $this->api()->getJson('/api/v1/projects')->assertForbidden();
        $this->api()->getJson("/api/v1/journal-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.lines.0.allocations')
            ->assertJsonMissingPath('data.lines.0.line_id');
    }

    public function test_a_project_of_another_organization_is_not_found(): void
    {
        $other = Organization::create(['name' => 'Other', 'currency' => 'CHF']);
        $foreign = Project::withoutGlobalScopes()->create(['organization_id' => $other->id, 'code' => 'X', 'name' => 'X']);

        $this->api()->getJson("/api/v1/projects/{$foreign->id}")->assertNotFound();
        $this->api()->withHeaders(['Idempotency-Key' => 'foreign'])
            ->patchJson("/api/v1/projects/{$foreign->id}", ['name' => 'Taken'])
            ->assertNotFound();
        $this->assertSame('X', Project::withoutGlobalScopes()->find($foreign->id)->name);
    }

    public function test_a_token_without_project_abilities_is_refused(): void
    {
        $result = $this->ownerA->createToken('limited', ['accounting.view']);
        $result->accessToken->update(['organization_id' => $this->orgA->id, 'type' => TokenType::Personal]);

        $this->withToken($result->plainTextToken)->getJson('/api/v1/projects')->assertForbidden();
    }

    public function test_an_explicit_null_keeps_the_allocations_on_amend(): void
    {
        $this->orgA->update(['posted_entry_edit_mode' => 'flexible']);
        Project::create(['organization_id' => $this->orgA->id, 'code' => 'ALPHA', 'name' => 'Alpha']);
        $created = $this->api()->withHeaders(['Idempotency-Key' => 'null-1'])->postJson('/api/v1/journal-entries', [
            'date' => '2026-08-10',
            'reference' => 'API-NULL',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '6000', 'debit' => '100.00', 'credit' => '0', 'allocations' => [['project_code' => 'ALPHA']]],
                ['account_code' => '1020', 'debit' => '0', 'credit' => '100.00'],
            ],
        ])->assertCreated();

        $this->api()->withHeaders(['If-Match' => '1', 'Idempotency-Key' => 'null-2'])
            ->patchJson('/api/v1/journal-entries/'.$created->json('data.id'), [
                'date' => '2026-08-10',
                'reference' => 'API-NULL',
                'description' => 'Text only',
                'lines' => [
                    ['account_code' => '6000', 'debit' => '100.00', 'credit' => '0', 'allocations' => null],
                    ['account_code' => '1020', 'debit' => '0', 'credit' => '100.00'],
                ],
            ])->assertOk()
            ->assertJsonPath('data.lines.0.allocations.0.project_code', 'ALPHA');
    }

    public function test_allocations_from_a_token_without_the_permission_are_refused(): void
    {
        Project::create(['organization_id' => $this->orgA->id, 'code' => 'ALPHA', 'name' => 'Alpha']);
        $result = $this->ownerA->createToken('no-allocate', ['accounting.view', 'accounting.create']);
        $result->accessToken->update(['organization_id' => $this->orgA->id, 'type' => TokenType::Personal]);

        $this->withToken($result->plainTextToken)->withHeaders(['Idempotency-Key' => 'forbidden-1'])
            ->postJson('/api/v1/journal-entries', [
                'date' => '2026-08-10',
                'reference' => 'API-FORBIDDEN',
                'status' => 'posted',
                'lines' => [
                    ['account_code' => '6000', 'debit' => '10.00', 'credit' => '0', 'allocations' => [['project_code' => 'ALPHA']]],
                    ['account_code' => '1020', 'debit' => '0', 'credit' => '10.00'],
                ],
            ])->assertForbidden()
            ->assertJsonPath('code', 'allocations_forbidden');

        $this->assertSame(0, JournalEntry::query()->where('reference', 'API-FORBIDDEN')->count());
    }

    public function test_cost_blocks_are_created_and_read_over_the_api(): void
    {
        $this->api()->withHeaders(['Idempotency-Key' => 'block-1'])
            ->postJson('/api/v1/budget-groups', ['year' => 2026, 'name' => 'Rent', 'plan_amount' => '3000.00', 'account_codes' => ['6000']])
            ->assertCreated()
            ->assertJsonPath('data.account_codes', ['6000']);

        $this->api()->withHeaders(['Idempotency-Key' => 'block-2'])
            ->postJson('/api/v1/budget-groups', ['year' => 2026, 'name' => 'Again', 'account_codes' => ['6000']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_taken');

        $this->api()->getJson('/api/v1/budget-groups?year=2026')
            ->assertOk()
            ->assertJsonPath('data.groups.0.name', 'Rent')
            ->assertJsonPath('data.groups.0.plan', '3000.00');
    }

    public function test_acquisitions_are_created_over_the_api(): void
    {
        $employee = Employee::factory()->for($this->orgA)->create(['first_name' => 'Anna', 'last_name' => 'A']);

        $this->api()->getJson('/api/v1/acquisitions/people')->assertOk()->assertJsonPath('data.0.name', 'Anna A');

        $this->api()->withHeaders(['Idempotency-Key' => 'acq-1'])
            ->postJson('/api/v1/acquisitions', [
                'item' => 'Display', 'purchased_on' => '2026-09-28', 'quantity' => 1, 'unit_price' => '1550',
                'vat_rate' => '8.10', 'status' => 'delivered', 'people' => [['employee_id' => $employee->id, 'share' => 100]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.net_amount', '1433.86');

        $this->api()->getJson('/api/v1/acquisitions?year=2026')->assertOk()->assertJsonPath('data.people.0.charge', '1433.86');
    }

    public function test_owners_set_steering_keys_over_the_api(): void
    {
        $anna = Employee::factory()->for($this->orgA)->create();
        $ben = Employee::factory()->for($this->orgA)->create();
        $project = Project::create(['organization_id' => $this->orgA->id, 'code' => 'ALPHA', 'name' => 'Alpha']);
        $block = BudgetGroup::create(['organization_id' => $this->orgA->id, 'year' => 2026, 'name' => 'Rent', 'kind' => 'running']);

        $this->api()->withHeaders(['Idempotency-Key' => 'shares-1'])
            ->putJson("/api/v1/projects/{$project->id}/shares", ['shares' => [['employee_id' => $anna->id, 'share' => 30], ['employee_id' => $ben->id, 'share' => 70]]])
            ->assertOk()->assertJsonCount(2, 'data');
        $this->api()->withHeaders(['Idempotency-Key' => 'shares-2'])
            ->putJson("/api/v1/projects/{$project->id}/shares", ['shares' => [['employee_id' => $anna->id, 'share' => 30]]])
            ->assertStatus(422)->assertJsonPath('code', 'shares_total');
        $this->api()->withHeaders(['Idempotency-Key' => 'people-1'])
            ->putJson("/api/v1/budget-groups/{$block->id}/people", ['people' => [['employee_id' => $anna->id, 'carry_share' => 50, 'credit_share' => 100], ['employee_id' => $ben->id, 'carry_share' => 50, 'credit_share' => 0]]])
            ->assertOk();
        $this->api()->withHeaders(['Idempotency-Key' => 'people-2'])
            ->putJson("/api/v1/budget-groups/{$block->id}/people", ['people' => [['employee_id' => $anna->id, 'carry_share' => 50, 'credit_share' => 0]]])
            ->assertStatus(422)->assertJsonPath('code', 'keys_total');
        $this->api()->withHeaders(['Idempotency-Key' => 'item-1'])
            ->postJson('/api/v1/steering-items', ['year' => 2026, 'employee_id' => $anna->id, 'label' => 'Salary', 'amount' => '11076.90'])
            ->assertCreated();
        $this->api()->getJson('/api/v1/steering?year=2026')->assertOk()->assertJsonPath('data.items.0.label', 'Salary');

        $admin = $this->createUserInOrg($this->orgA, 'admin');
        $adminToken = $this->createApiToken($admin, $this->orgA);
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->withHeaders(['Idempotency-Key' => 'item-2'])
            ->postJson('/api/v1/steering-items', ['year' => 2026, 'employee_id' => $anna->id, 'label' => 'X', 'amount' => '1'])
            ->assertForbidden();
    }

    private function api(): static
    {
        return $this->withToken($this->tokenA);
    }

    private function postEntry(): JournalEntry
    {
        return app(LedgerService::class)->postEntry($this->orgA->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'JE-'.uniqid(),
            description: 'x',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
            ],
        ));
    }
}
