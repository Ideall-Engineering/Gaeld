<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;
use Tests\Traits\WithRealConcurrency;

/**
 * `runConcurrently()` forks the current PHP process (pcntl_fork), so a
 * child inherits whatever this process already has in memory at fork time —
 * including the module's routes and AbilityCatalog registration from
 * `enableAccountantApiModule()` in setUp(). No extra wiring is needed for
 * the children to see the module's endpoints.
 */
class JournalCorrectionConcurrencyTest extends SecurityTestCase
{
    use WithAccountantApiModule, WithRealConcurrency;

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
            'reference' => 'RACE-CORR-1',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1020', 'debit' => '400.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '400.00'],
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

    public function test_it_serializes_a_real_concurrent_post_race_on_the_same_correction(): void
    {
        $prepareResponse = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'race-prepare')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Race test',
                'correction_date' => '2026-03-20',
            ]);
        $prepareResponse->assertCreated();
        $correctionId = $prepareResponse->json('data.id');
        $token = $this->tokenA;

        try {
            $results = $this->runConcurrently(2, function (int $i) use ($token, $correctionId): array {
                $response = $this->withToken($token)
                    ->withHeader('Idempotency-Key', "correction-post-race-{$i}")
                    ->postJson("/api/v1/journal-corrections/{$correctionId}/post");

                return [
                    'status' => $response->getStatusCode(),
                    'code' => $response->json('code'),
                ];
            });

            $statuses = array_column(array_column($results, 'result'), 'status');
            $codes = array_column(array_column($results, 'result'), 'code');
            sort($statuses);

            $this->assertSame([200, 409], $statuses, 'Exactly one concurrent post must win and one must be rejected.');
            $this->assertContains('journal_correction_state_conflict', $codes);

            $this->assertSame(
                1,
                JournalCorrection::query()->withoutGlobalScopes()->whereKey($correctionId)->where('status', 'posted')->count(),
            );
        } finally {
            $this->withCommittedCleanup(function (): void {
                DB::table('transaction_lines')
                    ->whereIn('journal_entry_id', JournalEntry::withoutGlobalScopes()->whereIn(
                        'organization_id', [$this->orgA->id, $this->orgB->id]
                    )->pluck('id'))
                    ->delete();
                DB::table('journal_corrections')->whereIn('organization_id', [$this->orgA->id, $this->orgB->id])->delete();
                DB::table('journal_entries')->whereIn('organization_id', [$this->orgA->id, $this->orgB->id])->delete();
                DB::table('accounts')->whereIn('organization_id', [$this->orgA->id, $this->orgB->id])->delete();
                $this->orgA->forceDelete();
                $this->orgB->forceDelete();
                $this->ownerA->delete();
                $this->ownerB->delete();
            });
        }
    }
}
