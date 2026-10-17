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
                'status' => 'pipeline',
                'expected_amount' => '5000.00',
                'target_date' => '2026-12-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.parent_code', 'AREA')
            ->assertJsonPath('data.status', 'pipeline')
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
            ->assertJsonPath('data.total_expenses', '40.00');
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
