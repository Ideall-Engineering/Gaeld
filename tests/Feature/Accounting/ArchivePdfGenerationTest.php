<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Actions\GenerateArchivePdfAction;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\LegalArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;
use Tests\Traits\WithRealConcurrency;

/**
 * Phase 6: Per-fiscal-year PDF archive generation
 * (P&L, balance sheet, general journal) for Swiss tax filing.
 */
class ArchivePdfGenerationTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization, WithRealConcurrency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        Storage::fake('local');
    }

    public function test_action_generates_three_pdfs_with_checksums(): void
    {
        $year = 2024;

        $results = app(GenerateArchivePdfAction::class)->execute($this->organization->id, $year);

        $this->assertCount(3, $results);

        foreach (['pdf_pnl', 'pdf_balance_sheet', 'pdf_journal'] as $documentType) {
            $archive = LegalArchive::where('organization_id', $this->organization->id)
                ->where('document_type', $documentType)
                ->where('fiscal_year', $year)
                ->first();

            $this->assertNotNull($archive, "Missing archive row for {$documentType}");
            $this->assertSame(64, strlen($archive->checksum_sha256));
            $this->assertSame("pdf-{$year}", $archive->document_id);
            $this->assertSame(1, $archive->version);
            $this->assertTrue(Storage::exists($archive->storage_path), "PDF not written for {$documentType}");

            $content = Storage::get($archive->storage_path);
            $this->assertStringStartsWith('%PDF-', $content);
            $this->assertSame(hash('sha256', $content), $archive->checksum_sha256);
        }
    }

    public function test_action_is_idempotent_within_cooldown(): void
    {
        $year = 2024;
        $action = app(GenerateArchivePdfAction::class);

        $action->execute($this->organization->id, $year);

        $firstChecksum = LegalArchive::where('organization_id', $this->organization->id)
            ->where('document_type', 'pdf_pnl')
            ->value('checksum_sha256');

        $results = $action->execute($this->organization->id, $year);

        foreach ($results as $r) {
            $this->assertFalse($r['regenerated'], "Expected {$r['type']} to be skipped within cooldown");
        }

        $this->assertSame(
            $firstChecksum,
            LegalArchive::where('organization_id', $this->organization->id)
                ->where('document_type', 'pdf_pnl')
                ->value('checksum_sha256'),
        );
    }

    public function test_action_serializes_pdf_generation_with_a_period_lock(): void
    {
        Cache::spy();

        app(GenerateArchivePdfAction::class)->execute($this->organization->id, 2024);

        Cache::shouldHaveReceived('lock')
            ->once()
            ->with("archive-pdf:{$this->organization->id}:2024", 600);
    }

    /**
     * FR-006: the mocked test above only proves `Cache::lock()` is invoked.
     * The action's own "already sealed" check (`$existing === null`) is a
     * read-then-write race: without genuine cross-process exclusion, two
     * callers starting at the same instant could both see no existing
     * archive and both insert a version-1 row for the same artifact. This
     * forks two real OS processes racing to generate the same fiscal year's
     * PDFs against the real Redis-backed lock and proves exactly one
     * version of each artifact is ever created.
     */
    public function test_action_serializes_real_concurrent_pdf_generation(): void
    {
        // Cache::lock() on the test suite's default "array" driver is an
        // in-memory mutex with no cross-process effect. Use the real
        // Redis-backed store production uses, so the lock this proves is
        // the lock that actually ships.
        config(['cache.default' => 'redis']);

        $orgId = $this->organization->id;
        $year = 2024;

        try {
            $results = $this->runConcurrently(2, function () use ($orgId, $year): array {
                $outcome = app(GenerateArchivePdfAction::class)->execute($orgId, $year);

                return array_map(fn (array $r): array => [
                    'type' => $r['type'],
                    'version' => $r['version'],
                    'regenerated' => $r['regenerated'],
                ], $outcome);
            });

            $this->assertCount(2, $results);

            foreach (['pdf_pnl', 'pdf_balance_sheet', 'pdf_journal'] as $documentType) {
                $archives = LegalArchive::where('organization_id', $orgId)
                    ->where('document_type', $documentType)
                    ->get();

                $this->assertCount(1, $archives, "Expected exactly one {$documentType} archive despite two concurrent callers.");
                $this->assertSame(1, $archives->first()->version);
                $this->assertTrue(Storage::exists($archives->first()->storage_path));
            }

            // Exactly one of the two callers actually did the rendering
            // work; the other, unblocked after the lock released, found a
            // freshly sealed archive and skipped regeneration.
            $regeneratedCounts = collect($results)
                ->pluck('result')
                ->collapse()
                ->groupBy('type')
                ->map(fn ($group) => $group->where('regenerated', true)->count());

            foreach ($regeneratedCounts as $type => $count) {
                $this->assertSame(1, $count, "Expected exactly one concurrent caller to regenerate {$type}.");
            }
        } finally {
            // The lock proof above required committing fixtures for real
            // cross-process visibility (see WithRealConcurrency); undo that
            // by hard-deleting everything this test created, via
            // withCommittedCleanup() so the deletes actually take effect
            // instead of being silently undone by RefreshDatabase's own
            // rollback.
            $this->withCommittedCleanup(function () use ($orgId): void {
                DB::table('legal_archives')->where('organization_id', $orgId)->delete();
                $this->organization->forceDelete();
                $this->user->delete();
            });
        }
    }

    public function test_action_regenerates_when_forced(): void
    {
        $year = 2024;
        $action = app(GenerateArchivePdfAction::class);

        $action->execute($this->organization->id, $year);

        // Delete the stored files so the seal-protection guard doesn't trigger.
        foreach (['pnl', 'balance-sheet', 'journal'] as $slug) {
            Storage::delete("archives/{$this->organization->id}/{$year}/pdf/{$slug}-{$year}.pdf");
        }

        $results = $action->execute($this->organization->id, $year, force: true);

        foreach ($results as $r) {
            $this->assertTrue($r['regenerated'], "Expected {$r['type']} to be regenerated with force");
        }
    }

    public function test_forced_regeneration_keeps_the_previous_pdf_version(): void
    {
        $year = 2024;
        $action = app(GenerateArchivePdfAction::class);

        $action->execute($this->organization->id, $year);
        $original = LegalArchive::query()
            ->where('organization_id', $this->organization->id)
            ->where('document_type', 'pdf_pnl')
            ->where('version', 1)
            ->firstOrFail();

        $action->execute($this->organization->id, $year, force: true);

        $versions = LegalArchive::query()
            ->where('organization_id', $this->organization->id)
            ->where('document_type', 'pdf_pnl')
            ->orderBy('version')
            ->get();
        $latest = $versions->last();

        $this->assertCount(2, $versions);
        $this->assertSame(1, $original->version);
        $this->assertSame(2, $latest->version);
        $this->assertNotSame($original->storage_path, $latest->storage_path);
        $this->assertTrue(Storage::exists($original->storage_path));
        $this->assertTrue(Storage::exists($latest->storage_path));
    }

    public function test_bundle_contains_only_the_latest_pdf_versions(): void
    {
        $year = 2024;
        $action = app(GenerateArchivePdfAction::class);

        $action->execute($this->organization->id, $year);
        $action->execute($this->organization->id, $year, force: true);

        $response = $this->actAsOrg()->get("/accounting/archives/year/{$year}/bundle");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'bundle-version-test-');
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = $zip->getNameIndex($index);
        }
        $zip->close();
        @unlink($tmp);

        // The bundle promises a set of files, not an order: nothing in
        // GenerateArchivePdfAction sorts the entries, so Postgres is free to
        // hand them back in whatever order the rows happen to sit in. Compare
        // as a set, or the test fails on physical row layout alone.
        sort($entries);

        $this->assertSame([
            'balance-sheet-2024-v2.pdf',
            'journal-2024-v2.pdf',
            'pnl-2024-v2.pdf',
        ], $entries);
    }

    public function test_download_pdf_endpoint_returns_pdf_response(): void
    {
        // downloadPdf() now redirects to a temporary signed URL; follow it manually
        // so we can still assert streamedContent() on the final file response.
        $redirect = $this->actAsOrg()->get('/accounting/archives/year/2024/pdf/pnl');
        $redirect->assertRedirect();

        $signedUrl = $redirect->headers->get('Location');
        $response = $this->get($signedUrl);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_explicit_fiscal_year_uses_stable_identity_for_pdf_artifacts(): void
    {
        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => 'Migration year',
            'start_date' => '2024-01-01',
            'end_date' => '2025-06-30',
            'status' => FiscalYearStatus::Operative,
        ]);

        app(GenerateArchivePdfAction::class)->execute(
            $this->organization->id,
            '2024',
            $fiscalYear->id,
        );

        $archive = LegalArchive::query()
            ->where('organization_id', $this->organization->id)
            ->where('document_type', 'pdf_pnl')
            ->firstOrFail();

        $this->assertSame("pdf-{$fiscalYear->id}", $archive->document_id);
        $this->assertStringContainsString("/{$fiscalYear->id}/pdf/", $archive->storage_path);

        $redirect = $this->actAsOrg()->get(
            "/accounting/archives/year/2024/pdf/pnl?fiscal_year_id={$fiscalYear->id}"
        );
        $redirect->assertRedirect();

        $this->get($redirect->headers->get('Location'))->assertOk();
    }

    public function test_download_pdf_endpoint_rejects_unknown_type(): void
    {
        $response = $this->actAsOrg()->get('/accounting/archives/year/2024/pdf/unknown');

        $response->assertStatus(404);
    }

    public function test_bundle_endpoint_returns_zip_with_three_pdfs(): void
    {
        $response = $this->actAsOrg()->get('/accounting/archives/year/2024/bundle');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/zip');

        $tmp = tempnam(sys_get_temp_dir(), 'bundle-test-');
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertSame(3, $zip->numFiles);
        $zip->close();
        @unlink($tmp);
    }

    public function test_regenerate_endpoint_forces_refresh(): void
    {
        $year = 2024;
        app(GenerateArchivePdfAction::class)->execute($this->organization->id, $year);

        $original = LegalArchive::where('organization_id', $this->organization->id)
            ->where('document_type', 'pdf_pnl')
            ->first();

        // Corrupt the file on disk (simulates storage bit-rot / accidental overwrite).
        // Recovery must append a new version and preserve the old evidence.
        Storage::put($original->storage_path, 'tampered');

        $response = $this->actAsOrg()
            ->post("/accounting/archives/year/{$year}/regenerate-pdfs");

        $response->assertRedirect();

        $latest = LegalArchive::query()
            ->where('organization_id', $this->organization->id)
            ->where('document_type', 'pdf_pnl')
            ->orderByDesc('version')
            ->firstOrFail();

        $this->assertSame(2, $latest->version);
        $this->assertSame('tampered', Storage::get($original->storage_path));
        $this->assertStringStartsWith('%PDF-', Storage::get($latest->storage_path));
    }
}
