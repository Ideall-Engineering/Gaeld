<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use Tests\Security\SecurityTestCase;

/**
 * Spec 011: the pages of the projects module and the contact bin, over the
 * API with the same rules as on the web.
 */
class ProjectParityApiTest extends SecurityTestCase
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

    public function test_a_job_is_deleted_only_while_nothing_hangs_on_it(): void
    {
        $free = Project::create(['organization_id' => $this->orgA->id, 'code' => 'FREE', 'name' => 'Free']);
        $used = Project::create(['organization_id' => $this->orgA->id, 'code' => 'USED', 'name' => 'Used']);
        $line = $this->postEntry()->lines()->where('account_id', $this->expense->id)->firstOrFail();
        LineAllocation::create(['organization_id' => $this->orgA->id, 'transaction_line_id' => $line->id, 'cost_center_id' => $used->id, 'share' => 100]);

        $this->api()->deleteJson("/api/v1/projects/{$free->id}")->assertNoContent();
        $this->api()->deleteJson("/api/v1/projects/{$used->id}")->assertUnprocessable()->assertJsonPath('code', 'project_in_use');
        $this->assertNull(Project::find($free->id));
        $this->assertNotNull(Project::find($used->id));
    }

    public function test_cost_blocks_are_changed_copied_and_deleted(): void
    {
        $group = BudgetGroup::create(['organization_id' => $this->orgA->id, 'year' => 2026, 'name' => 'Office', 'kind' => 'running']);

        $this->api()->patchJson("/api/v1/budget-groups/{$group->id}", ['name' => 'Büro', 'account_codes' => ['6000']])
            ->assertOk()->assertJsonPath('data.name', 'Büro')->assertJsonPath('data.account_codes', ['6000']);
        $this->api()->getJson("/api/v1/budget-groups/{$group->id}/lines")->assertOk()->assertJsonStructure(['rows']);
        $this->api()->postJson('/api/v1/budget-groups/copy-previous', ['year' => 2027])->assertOk()->assertJsonPath('data.copied', 1);
        $this->assertSame(1, BudgetGroup::query()->where('year', 2027)->count());

        $this->api()->deleteJson("/api/v1/budget-groups/{$group->id}")->assertNoContent();
        $this->assertNull(BudgetGroup::find($group->id));
    }

    public function test_receipts_without_a_job_are_listed_and_allocated(): void
    {
        $job = Project::create(['organization_id' => $this->orgA->id, 'code' => 'JOB', 'name' => 'Job']);
        $line = $this->postEntry()->lines()->where('account_id', $this->expense->id)->firstOrFail();

        $this->api()->getJson('/api/v1/unallocated-lines?year=2026&tab=expense&general=0')->assertOk()->assertJsonPath('data.year', 2026);
        $this->api()->postJson('/api/v1/unallocated-lines', ['items' => [['line_id' => $line->id, 'project_id' => $job->id]]])
            ->assertOk()->assertJsonPath('data.allocated', 1);
        $this->assertSame($job->id, LineAllocation::where('transaction_line_id', $line->id)->value('cost_center_id'));

        $this->api()->getJson('/api/v1/project-journal?year=2026')->assertOk()->assertJsonPath('data.year', 2026);
        $this->api()->getJson('/api/v1/allocation-reviews')->assertOk()->assertJsonPath('data', []);

        $other = $this->postEntry();
        $this->api()->postJson('/api/v1/journal-entry-allocations/bulk', ['entry_ids' => [$other->id], 'project_id' => $job->id])
            ->assertOk()->assertJsonPath('data.skipped', 0);
    }

    public function test_a_deleted_contact_is_listed_and_restored(): void
    {
        $contact = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Weg AG']);
        $contact->delete();

        $this->api()->getJson('/api/v1/contacts/trashed')->assertOk()->assertJsonPath('data.0.name', 'Weg AG');
        $this->api()->postJson("/api/v1/contacts/{$contact->uuid}/restore")->assertOk()->assertJsonPath('data.name', 'Weg AG');
        $this->assertFalse($contact->fresh()->trashed());
    }

    public function test_another_organisation_cannot_delete_the_job(): void
    {
        $job = Project::create(['organization_id' => $this->orgA->id, 'code' => 'MINE', 'name' => 'Mine']);
        $tokenB = $this->createApiToken($this->ownerB, $this->orgB);
        $this->orgB->update(['enabled_modules' => ['projects' => true]]);

        $this->assertDenied($this->withToken($tokenB)->deleteJson("/api/v1/projects/{$job->id}"));
        $this->assertNotNull(Project::withoutGlobalScopes()->find($job->id));
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
