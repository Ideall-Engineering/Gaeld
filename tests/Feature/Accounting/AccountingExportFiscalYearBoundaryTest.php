<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Reporting\Jobs\GenerateAccountingExportJob;
use App\Domains\Reporting\Services\AccountingExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;
use Tests\Traits\WithRealConcurrency;

class AccountingExportFiscalYearBoundaryTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization, WithRealConcurrency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_generate_accepts_explicit_fiscal_year_id_and_queues_it(): void
    {
        Queue::fake();

        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => 'Migration year',
            'start_date' => '2024-01-01',
            'end_date' => '2025-06-30',
            'status' => FiscalYearStatus::Operative,
        ]);

        $this->actAsOrg()
            ->post(route('accounting.export.generate'), [
                'fiscal_year_id' => $fiscalYear->id,
            ])
            ->assertRedirect(route('accounting.export'));

        Queue::assertPushed(GenerateAccountingExportJob::class, function (GenerateAccountingExportJob $job) use ($fiscalYear): bool {
            return $job->orgId === $this->organization->id
                && $job->fiscalYearId === $fiscalYear->id;
        });
    }

    public function test_export_uses_the_explicit_period_boundaries(): void
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
            ['2024-01-01', 'IN-RANGE-START'],
            ['2025-06-30', 'IN-RANGE-END'],
            ['2025-07-01', 'OUT-OF-RANGE'],
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

        $zipPath = app(AccountingExportService::class)->generateExport(
            $this->organization->id,
            '2024',
            $fiscalYear->id,
        );

        $this->assertStringContainsString(
            "accounting-{$this->organization->id}-{$fiscalYear->id}.zip",
            $zipPath,
        );

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $journal = $zip->getFromName('journal-entries.csv');
        $zip->close();

        $this->assertNotFalse($journal);
        $this->assertStringContainsString('IN-RANGE-START', $journal);
        $this->assertStringContainsString('IN-RANGE-END', $journal);
        $this->assertStringNotContainsString('OUT-OF-RANGE', $journal);

        @unlink($zipPath);
    }

    public function test_legacy_year_export_payload_remains_supported(): void
    {
        Queue::fake();

        $this->actAsOrg()
            ->post(route('accounting.export.generate'), [
                'fiscal_year' => '2024',
            ])
            ->assertRedirect(route('accounting.export'));

        Queue::assertPushed(GenerateAccountingExportJob::class, function (GenerateAccountingExportJob $job): bool {
            return $job->fiscalYear === '2024' && $job->fiscalYearId === null;
        });
    }

    public function test_export_serializes_same_period_requests_with_a_period_lock(): void
    {
        Storage::fake('local');

        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => 'Migration year',
            'start_date' => '2024-01-01',
            'end_date' => '2025-06-30',
            'status' => FiscalYearStatus::Operative,
        ]);
        Cache::spy();

        $path = app(AccountingExportService::class)->generateExport(
            $this->organization->id,
            '2024',
            $fiscalYear->id,
        );

        $this->assertFileExists($path);
        Cache::shouldHaveReceived('lock')
            ->once()
            ->with("accounting-export:{$this->organization->id}:{$fiscalYear->id}", 900);
    }

    /**
     * FR-014: the mocked test above only proves `Cache::lock()` is invoked
     * with the right key. It cannot catch a broken lock (wrong scope, no-op
     * driver, swallowed exception) because the spy never actually contends.
     * This forks two real OS processes that race for the same period's
     * export, both against the real Redis-backed cache, and proves they
     * were serialized rather than interleaved: the concurrent wall-clock
     * span must be close to two sequential runs, not one, and the resulting
     * artifact must be a single, valid, non-corrupted ZIP.
     */
    public function test_export_serializes_real_concurrent_requests_for_the_same_period(): void
    {
        Storage::fake('local');

        // The test suite defaults CACHE_STORE to the per-process "array"
        // driver for speed/isolation, but Cache::lock() on that driver is an
        // in-memory mutex that provides no exclusion at all across forked
        // processes. Force the real Redis-backed store — the same one
        // production uses — so the lock this test proves is the lock that
        // actually ships.
        config(['cache.default' => 'redis']);

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
            ['2024-01-01', 'CONCURRENT-START'],
            ['2025-06-30', 'CONCURRENT-END'],
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

        $orgId = $this->organization->id;
        $fiscalYearId = $fiscalYear->id;

        // Get a steady-state single-run timing reference before introducing
        // contention. The first call always pays one-time cold-start costs
        // (font loading, class autoloading) that a forked child skips by
        // inheriting the parent's already-warmed memory via copy-on-write,
        // so discard it and time a second, warm call instead — otherwise
        // the baseline is unfairly slower than the concurrent runs below.
        app(AccountingExportService::class)->generateExport($orgId, '2024', $fiscalYearId);
        $baselineStart = microtime(true);
        app(AccountingExportService::class)->generateExport($orgId, '2024', $fiscalYearId);
        $baselineDuration = microtime(true) - $baselineStart;

        try {
            $results = $this->runConcurrently(2, function () use ($orgId, $fiscalYearId): array {
                $path = app(AccountingExportService::class)->generateExport($orgId, '2024', $fiscalYearId);

                return ['path' => $path];
            });

            $paths = array_column(array_column($results, 'result'), 'path');
            $this->assertNotNull($paths[0]);
            $this->assertSame($paths[0], $paths[1], 'Both requests must resolve to the same deterministic artifact path.');
            $this->assertFileExists($paths[0]);

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($paths[0]) === true, 'The artifact produced under contention must be a valid, non-corrupted ZIP.');
            $journal = $zip->getFromName('journal-entries.csv');
            $zip->close();
            $this->assertNotFalse($journal);
            $this->assertStringContainsString('CONCURRENT-START', $journal);
            $this->assertStringContainsString('CONCURRENT-END', $journal);

            // Real mutual exclusion, not just a called-once assertion: two
            // truly parallel (unlocked) runs would finish in roughly one
            // baseline duration. A working lock forces them back-to-back,
            // so the observed span comfortably exceeds a single run.
            $concurrentSpan = max($results[0]['end'], $results[1]['end']) - min($results[0]['start'], $results[1]['start']);
            $this->assertGreaterThan(
                $baselineDuration * 1.4,
                $concurrentSpan,
                'Two same-period export requests finished too fast to have been serialized by the period lock.',
            );

            @unlink($paths[0]);
        } finally {
            // The lock proof above required committing fixtures for real
            // cross-process visibility (see WithRealConcurrency); undo that
            // by hard-deleting everything this test created, via
            // withCommittedCleanup() so the deletes actually take effect
            // instead of being silently undone by RefreshDatabase's own
            // rollback. transaction_lines restricts deletes on account_id,
            // so journal entries (which cascade their lines) must go before
            // accounts; deleting the organization then cascades accounts,
            // the fiscal year, and the organization_users pivot row.
            $this->withCommittedCleanup(function () use ($orgId): void {
                JournalEntry::where('organization_id', $orgId)->get()->each->delete();
                $this->organization->forceDelete();
                $this->user->delete();
            });
        }
    }
}
