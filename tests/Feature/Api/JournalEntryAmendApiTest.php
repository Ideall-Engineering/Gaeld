<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Api\Services\WebhookService;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Security\SecurityTestCase;

/**
 * Amending, deleting and reading the history of posted entries over the
 * API (spec 008, User Story 5).
 */
class JournalEntryAmendApiTest extends SecurityTestCase
{
    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        $this->orgA->update(['posted_entry_edit_mode' => PostedEntryEditMode::Flexible]);
        foreach ([['1020', 'Bank', AccountType::Asset], ['3000', 'Revenue', AccountType::Revenue], ['3400', 'Services', AccountType::Revenue]] as [$code, $name, $type]) {
            Account::create(['organization_id' => $this->orgA->id, 'code' => $code, 'name' => $name, 'type' => $type->value]);
        }
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
    }

    public function test_a_posted_entry_is_amended_with_if_match_and_reports_its_new_revision(): void
    {
        $entry = $this->postEntry('API-AMEND');

        $response = $this->amend($entry, 1, 'amend-1', '3400');

        $response->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.edit_state', 'open')
            ->assertJsonPath('data.lines.1.account_code', '3400');

        $revision = JournalEntryRevision::sole();
        $this->assertSame('api', $revision->source->value);
        $this->assertSame('amend-1', $revision->idempotency_key);
        $this->assertSame('Booked on the wrong revenue account', $revision->reason);
        $this->assertNotNull($revision->token_id);
    }

    public function test_a_replayed_amendment_does_not_create_a_second_revision(): void
    {
        $entry = $this->postEntry('API-REPLAY');

        $this->amend($entry, 1, 'amend-replay', '3400')->assertOk();
        $this->amend($entry, 1, 'amend-replay', '3400')->assertOk()->assertJsonPath('data.revision', 2);

        $this->assertSame(1, JournalEntryRevision::count());
        $this->assertSame(2, $entry->fresh()->revision);
    }

    public function test_a_stale_revision_is_answered_with_409_and_the_current_revision(): void
    {
        $entry = $this->postEntry('API-STALE');
        $this->amend($entry, 1, 'stale-1', '3400')->assertOk();

        $this->amend($entry, 1, 'stale-2', '3000')
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_revision')
            ->assertJsonPath('current_revision', 2);
    }

    public function test_reusing_an_idempotency_key_on_another_entry_conflicts(): void
    {
        $first = $this->postEntry('API-KEY-A');
        $second = $this->postEntry('API-KEY-B');

        $this->withToken($this->tokenA)
            ->withHeaders(['If-Match' => '1', 'Idempotency-Key' => 'same-key'])
            ->deleteJson("/api/v1/journal-entries/{$first->id}")
            ->assertNoContent();

        $this->withToken($this->tokenA)
            ->withHeaders(['If-Match' => '1', 'Idempotency-Key' => 'same-key'])
            ->deleteJson("/api/v1/journal-entries/{$second->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');

        $this->assertDatabaseHas('journal_entries', ['id' => $second->id]);
    }

    public function test_a_system_reference_cannot_be_chosen_over_the_api(): void
    {
        $this->withToken($this->tokenA)->postJson('/api/v1/journal-entries', [
            'date' => '2026-08-21',
            'reference' => 'OPENING-2026',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1020', 'debit' => '1.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '1.00'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('reference');
    }

    public function test_if_match_is_required(): void
    {
        $entry = $this->postEntry('API-NO-MATCH');

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'no-match')
            ->patchJson("/api/v1/journal-entries/{$entry->id}", $this->payload('3400'))
            ->assertStatus(428)
            ->assertJsonPath('code', 'revision_required');
    }

    public function test_an_idempotency_key_is_required(): void
    {
        $entry = $this->postEntry('API-NO-KEY');

        $this->withToken($this->tokenA)
            ->withHeader('If-Match', '"1"')
            ->patchJson("/api/v1/journal-entries/{$entry->id}", $this->payload('3400'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_key_required');
    }

    public function test_a_locked_entry_is_refused_with_its_edit_state(): void
    {
        $entry = $this->postEntry('API-LOCKED', '2026-05-10');
        JournalEntry::create([
            'organization_id' => $this->orgA->id,
            'date' => '2026-06-30',
            'reference' => 'VAT-SETTLEMENT-2026-04-01-2026-06-30',
            'description' => 'VAT settlement',
            'is_posted' => true,
            'type' => 'vat_settlement',
            'vat_period_start' => '2026-04-01',
            'vat_period_end' => '2026-06-30',
        ]);

        $this->amend($entry, 1, 'locked-1', '3400', '2026-05-10')
            ->assertStatus(422)
            ->assertJsonPath('code', 'entry_not_editable')
            ->assertJsonPath('edit_state', 'vat_locked');
    }

    public function test_strict_mode_is_reported_as_an_edit_state(): void
    {
        $this->orgA->update(['posted_entry_edit_mode' => PostedEntryEditMode::Strict]);
        $entry = $this->postEntry('API-STRICT');

        $this->amend($entry, 1, 'strict-1', '3400')
            ->assertStatus(422)
            ->assertJsonPath('edit_state', 'strict');
    }

    public function test_a_viewer_cannot_amend(): void
    {
        // Created through the ledger: Sanctum keeps the first user it resolved
        // for the rest of a test (see SecurityTestCase::createApiToken()).
        $entry = $this->postEntryDirectly('API-VIEWER');
        $viewer = $this->createUserInOrg($this->orgA, 'viewer');
        $token = $this->createApiToken($viewer, $this->orgA);

        $this->withToken($token)
            ->withHeaders(['If-Match' => '1', 'Idempotency-Key' => 'viewer-1'])
            ->patchJson("/api/v1/journal-entries/{$entry->id}", $this->payload('3400'))
            ->assertForbidden();
    }

    public function test_a_token_without_the_amend_ability_cannot_amend_even_for_an_owner(): void
    {
        $entry = $this->postEntryDirectly('API-SCOPED');
        $result = $this->ownerA->createToken('scoped', ['accounting.view', 'accounting.edit']);
        $result->accessToken->update(['organization_id' => $this->orgA->id, 'type' => TokenType::Personal]);

        $this->withToken($result->plainTextToken)
            ->withHeaders(['If-Match' => '1', 'Idempotency-Key' => 'scoped-1'])
            ->patchJson("/api/v1/journal-entries/{$entry->id}", $this->payload('3400'))
            ->assertForbidden();

        $this->assertSame(1, $entry->fresh()->revision);
    }

    public function test_another_organization_cannot_reach_the_entry(): void
    {
        $entry = $this->postEntryDirectly('API-FOREIGN');
        $tokenB = $this->createApiToken($this->ownerB, $this->orgB);

        $this->withToken($tokenB)
            ->withHeaders(['If-Match' => '1', 'Idempotency-Key' => 'foreign-1'])
            ->patchJson("/api/v1/journal-entries/{$entry->id}", $this->payload('3400'))
            ->assertNotFound();
    }

    public function test_a_posted_entry_is_deleted_and_its_last_state_stays_readable(): void
    {
        $entry = $this->postEntry('API-DELETE');

        $this->withToken($this->tokenA)
            ->withHeaders(['If-Match' => '1', 'Idempotency-Key' => 'delete-1'])
            ->deleteJson("/api/v1/journal-entries/{$entry->id}", ['reason' => 'Entered twice'])
            ->assertNoContent();

        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);

        $this->withToken($this->tokenA)
            ->getJson("/api/v1/journal-entry-revisions?kind=deleted&journal_entry_id={$entry->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'API-DELETE')
            ->assertJsonPath('data.0.reason', 'Entered twice')
            ->assertJsonPath('data.0.snapshot.entry.reference', 'API-DELETE');
    }

    public function test_deleting_a_posted_entry_needs_if_match(): void
    {
        $entry = $this->postEntry('API-DELETE-NO-MATCH');

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'delete-no-match')
            ->deleteJson("/api/v1/journal-entries/{$entry->id}")
            ->assertStatus(428);

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    public function test_the_revisions_of_an_entry_are_listed_oldest_first(): void
    {
        $entry = $this->postEntry('API-HISTORY');
        $this->amend($entry, 1, 'history-1', '3400')->assertOk();
        $this->amend($entry, 2, 'history-2', '3000')->assertOk();

        $this->withToken($this->tokenA)
            ->getJson("/api/v1/journal-entries/{$entry->id}/revisions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.revision', 1)
            ->assertJsonPath('data.1.revision', 2)
            ->assertJsonPath('data.0.kind', 'updated');
    }

    public function test_an_amendment_sends_the_updated_webhook(): void
    {
        $webhooks = Mockery::mock(WebhookService::class);
        $webhooks->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (string $org, string $event, array $payload) => $event === 'journal_entry.updated'
                && $payload['revision'] === 2
                && $payload['previous_revision'] === 1);
        $webhooks->shouldReceive('dispatch')->withArgs(fn (string $org, string $event) => $event !== 'journal_entry.updated');
        $this->app->instance(WebhookService::class, $webhooks);

        $entry = $this->postEntry('API-WEBHOOK');
        $this->amend($entry, 1, 'webhook-1', '3400')->assertOk();
    }

    private function postEntry(string $reference, string $date = '2026-08-21'): JournalEntry
    {
        $response = $this->withToken($this->tokenA)->postJson('/api/v1/journal-entries', [
            'date' => $date,
            'reference' => $reference,
            'description' => $reference,
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1020', 'debit' => '100.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '100.00'],
            ],
        ]);
        $response->assertCreated()->assertJsonPath('data.revision', 1);

        return JournalEntry::query()->whereKey($response->json('data.id'))->firstOrFail();
    }

    private function postEntryDirectly(string $reference): JournalEntry
    {
        $bank = Account::where('organization_id', $this->orgA->id)->where('code', '1020')->firstOrFail();
        $revenue = Account::where('organization_id', $this->orgA->id)->where('code', '3000')->firstOrFail();

        return app(LedgerService::class)->postEntry($this->orgA->id, new JournalEntryData(
            date: '2026-08-21',
            reference: $reference,
            description: $reference,
            lines: [
                new JournalLineData(accountId: (string) $bank->id, debit: '100.00', credit: '0'),
                new JournalLineData(accountId: (string) $revenue->id, debit: '0', credit: '100.00'),
            ],
        ));
    }

    /** @return array<string, mixed> */
    private function payload(string $revenueCode, string $date = '2026-08-21'): array
    {
        return [
            'date' => $date,
            'reference' => null,
            'description' => 'Amended',
            'reason' => 'Booked on the wrong revenue account',
            'lines' => [
                ['account_code' => '1020', 'debit' => '100.00', 'credit' => '0.00'],
                ['account_code' => $revenueCode, 'debit' => '0.00', 'credit' => '100.00'],
            ],
        ];
    }

    private function amend(JournalEntry $entry, int $revision, string $key, string $revenueCode, string $date = '2026-08-21'): TestResponse
    {
        $payload = $this->payload($revenueCode, $date);
        $payload['reference'] = $entry->reference;

        return $this->withToken($this->tokenA)
            ->withHeaders(['If-Match' => "\"{$revision}\"", 'Idempotency-Key' => $key])
            ->patchJson("/api/v1/journal-entries/{$entry->id}", $payload);
    }
}
