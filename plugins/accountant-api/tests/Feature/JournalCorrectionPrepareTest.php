<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

class JournalCorrectionPrepareTest extends SecurityTestCase
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
            'reference' => 'PREP-1',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1020', 'debit' => '500.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '500.00'],
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

    public function test_prepare_without_a_replacement_payload_copies_the_original_in_full(): void
    {
        $response = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'prepare-full-copy')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Wrong amount',
                'correction_date' => '2026-03-20',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.original.id', $this->original->id);

        $replacementId = $response->json('data.replacement.id');
        $this->assertDatabaseHas('transaction_lines', [
            'journal_entry_id' => $replacementId,
            'debit' => '500.00',
        ]);
    }

    public function test_prepare_with_an_explicit_replacement_payload_uses_it_instead(): void
    {
        $response = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'prepare-explicit')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Wrong amount, corrected to 600',
                'correction_date' => '2026-03-20',
                'lines' => [
                    ['account_code' => '1020', 'debit' => '600.00', 'credit' => '0.00'],
                    ['account_code' => '3000', 'debit' => '0.00', 'credit' => '600.00'],
                ],
            ]);

        $response->assertCreated();
        $replacementId = $response->json('data.replacement.id');
        $this->assertDatabaseHas('transaction_lines', [
            'journal_entry_id' => $replacementId,
            'debit' => '600.00',
        ]);
    }

    public function test_prepare_requires_reason_and_correction_date(): void
    {
        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'prepare-missing-fields')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason', 'correction_date']);
    }

    public function test_a_second_prepare_on_the_same_original_returns_a_stable_conflict_code(): void
    {
        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'prepare-first')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'First correction',
                'correction_date' => '2026-03-20',
            ])->assertCreated();

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'prepare-second')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Second attempt',
                'correction_date' => '2026-03-21',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'journal_entry_already_corrected');
    }
}
