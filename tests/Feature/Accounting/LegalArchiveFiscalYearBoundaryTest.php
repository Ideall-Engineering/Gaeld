<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\LegalArchive;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\LegalArchivingService;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;
use Tests\Traits\WithRealConcurrency;

class LegalArchiveFiscalYearBoundaryTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization, WithRealConcurrency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_archive_uses_explicit_period_and_is_idempotent(): void
    {
        Storage::fake('local');

        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => 'Migration year',
            'start_date' => '2024-01-01',
            'end_date' => '2025-06-30',
            'status' => FiscalYearStatus::Operative,
        ]);
        $bank = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020',
            'name' => 'Bank',
            'type' => AccountType::Asset->value,
        ]);
        $revenue = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '3000',
            'name' => 'Revenue',
            'type' => AccountType::Revenue->value,
        ]);
        $ledger = app(LedgerService::class);

        foreach ([
            ['2024-01-01', 'ARCHIVE-START'],
            ['2025-06-30', 'ARCHIVE-END'],
            ['2025-07-01', 'ARCHIVE-AFTER'],
        ] as [$date, $reference]) {
            $ledger->postEntry($this->organization->id, new JournalEntryData(
                date: $date,
                reference: $reference,
                description: $reference,
                lines: [
                    new JournalLineData(accountId: (string) $bank->id, debit: '100.00', credit: '0'),
                    new JournalLineData(accountId: (string) $revenue->id, debit: '0', credit: '100.00'),
                ],
            ));
        }

        $service = app(LegalArchivingService::class);
        $service->archiveFiscalYear($this->organization->id, '2024', $fiscalYear->id);

        $archives = LegalArchive::query()
            ->where('document_type', 'journal_entry')
            ->get();

        $this->assertCount(2, $archives);
        $this->assertTrue($archives->every(fn (LegalArchive $archive): bool => $archive->fiscal_year_id === $fiscalYear->id));
        $this->assertFalse(Storage::disk('local')->exists('archives/'.$this->organization->id.'/2025/journal_entry/ARCHIVE-AFTER.json'));

        $service->archiveFiscalYear($this->organization->id, '2024', $fiscalYear->id);

        $this->assertSame(2, LegalArchive::query()->where('document_type', 'journal_entry')->count());
    }

    public function test_archive_does_not_include_another_organization(): void
    {
        Storage::fake('local');

        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);
        $foreignOrganization = Organization::factory()->create();

        $foreignEntry = JournalEntry::create([
            'organization_id' => $foreignOrganization->id,
            'date' => '2024-06-01',
            'reference' => 'FOREIGN-ARCHIVE',
            'description' => 'Foreign organization entry',
            'is_posted' => true,
        ]);

        app(LegalArchivingService::class)->archiveFiscalYear(
            $this->organization->id,
            '2024',
            $fiscalYear->id,
        );

        $this->assertDatabaseMissing('legal_archives', [
            'document_id' => $foreignEntry->id,
        ]);
    }

    public function test_legacy_archive_rows_without_fiscal_year_provenance_remain_readable(): void
    {
        $archive = LegalArchive::create([
            'organization_id' => $this->organization->id,
            'document_type' => 'invoice',
            'document_id' => 'legacy-archive',
            'fiscal_year' => 2024,
            'fiscal_year_id' => null,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => 'archives/legacy.json',
            'archived_at' => now(),
            'expires_at' => now()->addYears(10),
        ]);

        $this->assertNull($archive->fiscal_year_id);
        $this->assertSame(2024, $archive->fiscal_year);
    }

    public function test_archive_acquires_the_organization_period_lock(): void
    {
        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(10)->andReturnTrue();
        $lock->shouldReceive('release')->once()->andReturnTrue();
        Cache::shouldReceive('lock')
            ->once()
            ->with("archive:{$this->organization->id}:{$fiscalYear->id}", 600)
            ->andReturn($lock);

        app(LegalArchivingService::class)->archiveFiscalYear(
            $this->organization->id,
            '2024',
            $fiscalYear->id,
        );
    }

    /**
     * FR-006: the mocked test above only proves `Cache::lock()` is invoked.
     * `archiveFiscalYear()` reads documents `whereNull('archived_at')` and
     * then updates them one by one — that read-then-write is not atomic, so
     * without a genuinely shared lock, two callers that start at the same
     * instant would both see the same unarchived rows and both archive them,
     * doubling the `legal_archives` rows. This forks two real OS processes
     * racing for the same period against the real Redis-backed lock and
     * proves each document is archived exactly once.
     */
    public function test_archive_serializes_real_concurrent_requests_for_the_same_period(): void
    {
        Storage::fake('local');

        // Cache::lock() on the test suite's default "array" driver is an
        // in-memory mutex with no cross-process effect. Use the real
        // Redis-backed store production uses, so the lock this proves is
        // the lock that actually ships.
        config(['cache.default' => 'redis']);

        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);
        $bank = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020',
            'name' => 'Bank',
            'type' => AccountType::Asset->value,
        ]);
        $revenue = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '3000',
            'name' => 'Revenue',
            'type' => AccountType::Revenue->value,
        ]);

        $ledger = app(LedgerService::class);
        foreach (['RACE-1', 'RACE-2', 'RACE-3', 'RACE-4'] as $reference) {
            $ledger->postEntry($this->organization->id, new JournalEntryData(
                date: '2024-03-01',
                reference: $reference,
                description: $reference,
                lines: [
                    new JournalLineData(accountId: (string) $bank->id, debit: '50.00', credit: '0'),
                    new JournalLineData(accountId: (string) $revenue->id, debit: '0', credit: '50.00'),
                ],
            ));
        }

        $orgId = $this->organization->id;
        $fiscalYearId = $fiscalYear->id;

        try {
            $results = $this->runConcurrently(2, function () use ($orgId, $fiscalYearId): array {
                app(LegalArchivingService::class)->archiveFiscalYear($orgId, '2024', $fiscalYearId);

                return ['done' => true];
            });

            $this->assertCount(2, $results);

            // Exactly one archive row per document -- a broken/no-op lock
            // would let both processes archive the same 4 entries, yielding 8.
            $archives = LegalArchive::query()
                ->where('organization_id', $orgId)
                ->where('document_type', 'journal_entry')
                ->get();

            $this->assertCount(4, $archives, 'Expected exactly one archive per journal entry despite two concurrent callers.');
            $this->assertSame(4, $archives->pluck('document_id')->unique()->count());

            $this->assertSame(
                4,
                JournalEntry::where('organization_id', $orgId)->whereNotNull('archived_at')->count(),
            );
        } finally {
            // The lock proof above required committing fixtures for real
            // cross-process visibility (see WithRealConcurrency); undo that
            // by hard-deleting everything this test created, via
            // withCommittedCleanup() so the deletes actually take effect
            // instead of being silently undone by RefreshDatabase's own
            // rollback. Archived journal entries are locked against Eloquent
            // delete/update by LocksArchivedRecord, so clean up through raw
            // queries instead.
            $this->withCommittedCleanup(function () use ($orgId, $fiscalYearId): void {
                DB::table('legal_archives')->where('organization_id', $orgId)->delete();
                DB::table('transaction_lines')
                    ->whereIn('journal_entry_id', JournalEntry::where('organization_id', $orgId)->pluck('id'))
                    ->delete();
                DB::table('journal_entries')->where('organization_id', $orgId)->delete();
                DB::table('accounts')->where('organization_id', $orgId)->delete();
                DB::table('fiscal_years')->where('id', $fiscalYearId)->delete();
                $this->organization->forceDelete();
                $this->user->delete();
            });
        }
    }
}
