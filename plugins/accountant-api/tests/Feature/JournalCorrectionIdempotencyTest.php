<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

class JournalCorrectionIdempotencyTest extends SecurityTestCase
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
            'reference' => 'IDEM-CORR-1',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1020', 'debit' => '150.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '150.00'],
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

    public function test_prepare_requires_an_idempotency_key(): void
    {
        $this->withToken($this->tokenA)
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'No key given',
                'correction_date' => '2026-03-20',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_key_required');
    }

    public function test_repeating_the_same_idempotency_key_and_payload_replays_the_same_result(): void
    {
        $payload = ['reason' => 'Replay test', 'correction_date' => '2026-03-20'];

        $first = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'idem-replay-key')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", $payload);
        $first->assertCreated();

        $second = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'idem-replay-key')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", $payload);

        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, JournalCorrection::where('original_journal_entry_id', $this->original->id)->count());
    }

    public function test_the_same_idempotency_key_with_a_different_payload_conflicts(): void
    {
        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'idem-conflict-key')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'First payload',
                'correction_date' => '2026-03-20',
            ])
            ->assertCreated();

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'idem-conflict-key')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Different payload, same key',
                'correction_date' => '2026-03-21',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');
    }
}
