<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Actions\PostVatSettlementAction;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithRealConcurrency;

class JournalEntryLifecycleApiTest extends SecurityTestCase
{
    use WithRealConcurrency;

    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        $this->createAccount('1020', 'Bank', AccountType::Asset->value);
        $this->createAccount('3000', 'Revenue', AccountType::Revenue->value);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
    }

    public function test_it_lists_entries_with_status_and_date_filters(): void
    {
        $this->createEntry('LIFE-POSTED', 'posted', '2026-08-21');
        $this->createEntry('LIFE-DRAFT', 'draft', '2026-08-20');

        $response = $this->withToken($this->tokenA)
            ->getJson('/api/v1/journal-entries?status=posted&from=2026-08-21&to=2026-08-21');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'LIFE-POSTED')
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_it_posts_a_draft_once(): void
    {
        $entry = $this->createEntry('LIFE-TO-POST', 'draft', '2026-08-21');

        $response = $this->withToken($this->tokenA)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        $response->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'is_posted' => true,
        ]);

        $this->withToken($this->tokenA)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post")
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id);

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'post-already-completed')
            ->postJson("/api/v1/journal-entries/{$entry->id}/post")
            ->assertStatus(409)
            ->assertJsonPath('code', 'concurrent_transition');
    }

    /**
     * The test above only proves the *sequential* case: posting an entry
     * that is already posted returns 409. It never proves the row lock in
     * `LedgerService::postDraft()` (`lockForUpdate()`) actually serializes a
     * genuine race. This forks two real OS processes that both try to post
     * the same draft at the same instant, each with its own Idempotency-Key
     * (so neither can be served from the idempotency cache), and proves
     * exactly one becomes `posted` while the other observes the row already
     * posted and receives `409 concurrent_transition` — never both
     * succeeding, and never a corrupted/double-posted entry.
     */
    public function test_it_serializes_a_real_concurrent_post_race(): void
    {
        $entry = $this->createEntry('LIFE-RACE', 'draft', '2026-08-21');
        $entryId = $entry->id;
        $token = $this->tokenA;

        try {
            $results = $this->runConcurrently(2, function (int $i) use ($token, $entryId): array {
                $response = $this->withToken($token)
                    ->withHeader('Idempotency-Key', "post-race-{$i}")
                    ->postJson("/api/v1/journal-entries/{$entryId}/post");

                return [
                    'status' => $response->getStatusCode(),
                    'code' => $response->json('code'),
                ];
            });

            $statuses = array_column(array_column($results, 'result'), 'status');
            $codes = array_column(array_column($results, 'result'), 'code');
            sort($statuses);

            $this->assertSame([200, 409], $statuses, 'Exactly one concurrent post must win and one must be rejected.');
            $this->assertContains('concurrent_transition', $codes);

            $this->assertSame(1, JournalEntry::query()->whereKey($entryId)->where('is_posted', true)->count());
            $this->assertDatabaseHas('journal_entries', ['id' => $entryId, 'is_posted' => true]);
        } finally {
            // The lock proof above required committing fixtures for real
            // cross-process visibility (see WithRealConcurrency); undo that
            // by hard-deleting everything SecurityTestCase::setUp() and this
            // test created, via withCommittedCleanup() so the deletes
            // actually take effect instead of being silently undone by
            // RefreshDatabase's own rollback.
            $this->withCommittedCleanup(function (): void {
                DB::table('transaction_lines')
                    ->whereIn('journal_entry_id', JournalEntry::whereIn(
                        'organization_id', [$this->orgA->id, $this->orgB->id]
                    )->pluck('id'))
                    ->delete();
                DB::table('journal_entries')->whereIn('organization_id', [$this->orgA->id, $this->orgB->id])->delete();
                DB::table('accounts')->whereIn('organization_id', [$this->orgA->id, $this->orgB->id])->delete();
                $this->orgA->forceDelete();
                $this->orgB->forceDelete();
                $this->ownerA->delete();
                $this->ownerB->delete();
            });
        }
    }

    public function test_it_reverses_a_posted_entry_without_editing_the_original(): void
    {
        $entry = $this->createEntry('LIFE-REVERSIBLE', 'posted', '2026-08-21');

        $response = $this->withToken($this->tokenA)
            ->postJson("/api/v1/journal-entries/{$entry->id}/reverse", [
                'description' => 'Correction from external system',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.reference', 'REV-LIFE-REVERSIBLE');
        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'reference' => 'LIFE-REVERSIBLE',
            'is_posted' => true,
        ]);

        $this->withToken($this->tokenA)
            ->postJson("/api/v1/journal-entries/{$entry->id}/reverse", [
                'description' => 'Correction from external system',
            ])
            ->assertOk()
            ->assertJsonPath('data.reference', 'REV-LIFE-REVERSIBLE');
    }

    public function test_it_deletes_a_draft_only(): void
    {
        $entry = $this->createEntry('LIFE-DELETE', 'draft', '2026-08-21');

        $response = $this->withToken($this->tokenA)
            ->deleteJson("/api/v1/journal-entries/{$entry->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
    }

    public function test_publishing_a_draft_in_a_closed_fiscal_year_is_rejected(): void
    {
        FiscalYear::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Closed 2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => FiscalYearStatus::Closed,
        ]);
        $entry = $this->createEntry('LIFE-CLOSED', 'draft', '2025-06-30');

        $this->withToken($this->tokenA)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post")
            ->assertStatus(422)
            ->assertJsonPath('code', 'fiscal_year_closed');
    }

    /**
     * The contract documents a generic `domain_error` (422) class for any
     * domain exception without its own named code
     * (`contract/api-contract.json` → `domain_exception_422`), but nothing
     * exercised it. `VatPeriodLockedException` is exactly that case: it is
     * not in `JournalEntryApiController::domainError()`'s named match, so it
     * must fall through to the generic code. Posting a VAT line into an
     * already-settled VAT period is the real path that throws it.
     */
    public function test_posting_a_vat_line_into_a_settled_period_returns_the_generic_domain_error(): void
    {
        Account::create(['organization_id' => $this->orgA->id, 'code' => '1170', 'name' => 'VAT Input', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->orgA->id, 'code' => '2200', 'name' => 'VAT Output', 'type' => AccountType::Liability->value]);
        Account::create(['organization_id' => $this->orgA->id, 'code' => '2201', 'name' => 'VAT Settlement', 'type' => AccountType::Liability->value]);
        $vatRate = VatRate::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Standard',
            'rate' => 8.10,
            'code' => 'NORMAL',
            'is_default' => true,
        ]);

        $baseEntry = $this->createEntry('VAT-BASE', 'posted', '2026-01-15');
        VatEntry::create([
            'journal_entry_id' => $baseEntry->id,
            'vat_rate_id' => $vatRate->id,
            'base_amount' => 1000.00,
            'vat_amount' => 81.00,
            'type' => VatEntryType::Output->value,
        ]);

        // Settle Q1 2026 so any new VAT line dated inside it is locked.
        app(PostVatSettlementAction::class)
            ->execute($this->orgA->id, '2026-01-01', '2026-03-31');

        $response = $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'vat-locked-attempt')
            ->postJson('/api/v1/journal-entries', [
                'date' => '2026-02-10',
                'reference' => 'VAT-LOCKED-ATTEMPT',
                'description' => 'Entry inside a settled VAT period',
                'status' => 'posted',
                'lines' => [
                    [
                        'account_code' => '1020',
                        'debit' => '108.10',
                        'credit' => '0.00',
                        'vat_type' => 'output',
                        'vat_rate_id' => $vatRate->uuid,
                        'vat_amount' => '8.10',
                    ],
                    ['account_code' => '3000', 'debit' => '0.00', 'credit' => '108.10'],
                ],
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'domain_error');
    }

    private function createEntry(string $reference, string $status, string $date): JournalEntry
    {
        $response = $this->withToken($this->tokenA)->postJson('/api/v1/journal-entries', [
            'date' => $date,
            'reference' => $reference,
            'description' => $reference,
            'status' => $status,
            'lines' => [
                ['account_code' => '1020', 'debit' => '100.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '100.00'],
            ],
        ]);

        $response->assertCreated();

        return JournalEntry::query()->whereKey($response->json('data.id'))->firstOrFail();
    }

    private function createAccount(string $code, string $name, string $type): void
    {
        Account::create([
            'organization_id' => $this->orgA->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
        ]);
    }
}
