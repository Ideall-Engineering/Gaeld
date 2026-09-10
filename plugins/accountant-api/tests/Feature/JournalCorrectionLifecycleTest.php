<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

class JournalCorrectionLifecycleTest extends SecurityTestCase
{
    use WithAccountantApiModule;

    private string $tokenA;

    private JournalEntry $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAccountantApiModule();

        config(['features.api_access' => true]);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);

        Account::create(['organization_id' => $this->orgA->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->orgA->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);

        $response = $this->withToken($this->tokenA)->postJson('/api/v1/journal-entries', [
            'date' => '2026-03-16',
            'reference' => 'LIFE-CORR-1',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1020', 'debit' => '300.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '300.00'],
            ],
        ]);
        $response->assertCreated();
        $this->original = JournalEntry::query()->whereKey($response->json('data.id'))->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->tearDownAccountantApiModule();
        parent::tearDown();
    }

    private function prepareCorrection(string $idempotencyKey = 'lifecycle-prepare'): string
    {
        $response = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Wrong amount',
                'correction_date' => '2026-03-20',
            ]);
        $response->assertCreated();

        return $response->json('data.id');
    }

    public function test_show_returns_the_correction_with_links_to_all_three_entries(): void
    {
        $correctionId = $this->prepareCorrection();

        $this->withToken($this->tokenA)
            ->getJson("/api/v1/journal-corrections/{$correctionId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.original.id', $this->original->id)
            ->assertJsonStructure(['data' => ['original', 'reversal', 'replacement']]);
    }

    public function test_update_replacement_changes_its_lines(): void
    {
        $correctionId = $this->prepareCorrection();

        $response = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'lifecycle-update')
            ->putJson("/api/v1/journal-corrections/{$correctionId}/replacement", [
                'date' => '2026-03-20',
                'lines' => [
                    ['account_code' => '1020', 'debit' => '350.00', 'credit' => '0.00'],
                    ['account_code' => '3000', 'debit' => '0.00', 'credit' => '350.00'],
                ],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('transaction_lines', [
            'journal_entry_id' => $response->json('data.replacement.id'),
            'debit' => '350.00',
        ]);
    }

    public function test_post_posts_both_entries_together(): void
    {
        $correctionId = $this->prepareCorrection();

        $response = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'lifecycle-post')
            ->postJson("/api/v1/journal-corrections/{$correctionId}/post");

        $response->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('journal_entries', ['id' => $response->json('data.reversal.id'), 'is_posted' => true]);
        $this->assertDatabaseHas('journal_entries', ['id' => $response->json('data.replacement.id'), 'is_posted' => true]);
    }

    public function test_posting_an_already_posted_correction_returns_a_stable_conflict_code(): void
    {
        $correctionId = $this->prepareCorrection();
        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'lifecycle-post-once')
            ->postJson("/api/v1/journal-corrections/{$correctionId}/post")
            ->assertOk();

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'lifecycle-post-twice')
            ->postJson("/api/v1/journal-corrections/{$correctionId}/post")
            ->assertStatus(409)
            ->assertJsonPath('code', 'journal_correction_state_conflict');
    }

    public function test_destroy_discards_an_open_correction(): void
    {
        $correctionId = $this->prepareCorrection();

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'lifecycle-destroy')
            ->deleteJson("/api/v1/journal-corrections/{$correctionId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('journal_corrections', ['id' => $correctionId]);
        $this->assertDatabaseHas('journal_entries', ['id' => $this->original->id]);
    }

    public function test_destroy_of_a_posted_correction_returns_a_stable_conflict_code(): void
    {
        $correctionId = $this->prepareCorrection();
        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'lifecycle-destroy-post')
            ->postJson("/api/v1/journal-corrections/{$correctionId}/post")
            ->assertOk();

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'lifecycle-destroy-after-post')
            ->deleteJson("/api/v1/journal-corrections/{$correctionId}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'journal_correction_state_conflict');
    }
}
