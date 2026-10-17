<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\LegalArchivingService;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\LineAllocationService;
use App\Domains\Reporting\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Allocations are reporting facts only: allocating, re-splitting and
 * clearing them must leave every figure of the books exactly as it was
 * (spec 009, FR-007 and SC-001), including archive checksums (SC-003).
 */
class AllocationLedgerInvarianceTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Project $alpha;

    private Project $beta;

    private Employee $person;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->alpha = $this->makeProject('ALPHA');
        $this->beta = $this->makeProject('BETA');
        $this->person = Employee::factory()->for($this->organization)->create();
        $this->buildBooks();
    }

    public function test_allocating_resplitting_and_clearing_leave_the_books_unchanged(): void
    {
        $baseline = $this->fingerprint();
        $service = app(LineAllocationService::class);

        // Everything to one project.
        foreach (JournalEntry::all() as $entry) {
            $service->replaceForEntry($entry, $this->linesOf($entry, fn () => [
                new LineAllocationData(projectId: $this->alpha->id, employeeId: null),
            ]), AllocationSource::Manual);
        }
        $this->assertGreaterThan(5, LineAllocation::count());
        $this->assertEquals($baseline, $this->fingerprint(), 'Allocating changed the books.');

        // Split across projects and a person, partly unallocated.
        foreach (JournalEntry::all() as $entry) {
            $service->replaceForEntry($entry, $this->linesOf($entry, fn () => [
                new LineAllocationData(projectId: $this->alpha->id, employeeId: null, share: '33.3333'),
                new LineAllocationData(projectId: $this->beta->id, employeeId: $this->person->id, share: '50'),
            ]), AllocationSource::Manual);
        }
        $this->assertEquals($baseline, $this->fingerprint(), 'Re-splitting changed the books.');

        // Bulk over the web, overwriting.
        $this->actAsOrg()->post('/accounting/journal-entry-allocations/bulk', [
            'entry_ids' => JournalEntry::pluck('id')->all(),
            'project_id' => $this->beta->id,
            'overwrite' => true,
        ])->assertSessionHasNoErrors();
        $this->assertEquals($baseline, $this->fingerprint(), 'Bulk allocation changed the books.');

        // Cleared again.
        foreach (JournalEntry::all() as $entry) {
            $service->replaceForEntry($entry, $this->linesOf($entry, fn () => []), AllocationSource::Manual);
        }
        $this->assertSame(0, LineAllocation::count());
        $this->assertEquals($baseline, $this->fingerprint(), 'Clearing changed the books.');
    }

    public function test_an_archived_entry_keeps_its_archive_checksum(): void
    {
        $entry = JournalEntry::query()->where('reference', 'INV-1')->sole();
        $archiving = app(LegalArchivingService::class);
        $entry->update(['archived_at' => now()]);
        $before = $archiving->archiveDocument($entry->fresh('lines'), 'journal_entry');

        app(LineAllocationService::class)->replaceForEntry($entry, $this->linesOf($entry, fn () => [
            new LineAllocationData(projectId: $this->alpha->id, employeeId: null),
        ]), AllocationSource::Manual);

        $this->assertTrue($archiving->verifyIntegrity($before));
        $again = $archiving->archiveDocument($entry->fresh('lines'), 'journal_entry');
        $this->assertSame($before->checksum_sha256, $again->checksum_sha256, 'The archived payload must not carry allocations.');
    }

    /**
     * Sales and purchases with VAT, a split purchase, a payment, a draft,
     * a reversal pair, a settled VAT quarter and a closing-type entry.
     */
    private function buildBooks(): void
    {
        $ledger = app(LedgerService::class);
        $rate = VatRate::factory()->for($this->organization)->create(['rate' => '8.10']);
        $receivable = $this->makeAccount('1100', 'Receivables', AccountType::Asset);
        $vatPayable = $this->makeAccount('2200', 'VAT payable', AccountType::Liability);
        $vatInput = $this->makeAccount('1170', 'Input VAT', AccountType::Asset);
        $equity = $this->makeAccount('2979', 'Result', AccountType::Equity);

        $ledger->postEntry($this->organization->id, new JournalEntryData('2026-02-10', 'INV-1', 'Sale', [
            new JournalLineData(accountId: (string) $receivable->id, debit: '1081.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->revenue->id, debit: '0', credit: '1000.00', vatRateId: $rate->uuid, vatAmount: '81.00', vatType: VatEntryType::Output->value),
            new JournalLineData(accountId: (string) $vatPayable->id, debit: '0', credit: '81.00'),
        ]));
        $ledger->postEntry($this->organization->id, new JournalEntryData('2026-02-20', 'PAY-1', 'Payment', [
            new JournalLineData(accountId: (string) $this->bank->id, debit: '1081.00', credit: '0'),
            new JournalLineData(accountId: (string) $receivable->id, debit: '0', credit: '1081.00'),
        ]));
        $ledger->postEntry($this->organization->id, new JournalEntryData('2026-03-05', 'EXP-1', 'Split purchase', [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '200.00', credit: '0', vatRateId: $rate->uuid, vatAmount: '16.20', vatType: VatEntryType::Input->value),
            new JournalLineData(accountId: (string) $this->otherExpense->id, debit: '133.33', credit: '0'),
            new JournalLineData(accountId: (string) $vatInput->id, debit: '16.20', credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '349.53'),
        ]));
        $this->lockVatQuarter('2026-01-01', '2026-03-31');

        $wrong = $this->postSimpleEntry('2026-05-02', 'EXP-2', '45.55');
        $ledger->postDraft($ledger->reverseEntry($wrong, date: '2026-05-03'));
        $this->postSimpleEntry('2026-06-15', 'EXP-3', '12.40', $this->otherExpense);

        $ledger->createDraft($this->organization->id, new JournalEntryData('2026-07-01', 'DRAFT-1', 'Draft', [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '9.90', credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '9.90'),
        ]));

        $closing = $ledger->postEntry($this->organization->id, new JournalEntryData('2026-12-31', 'CLOSE-2026', 'Closing', [
            new JournalLineData(accountId: (string) $this->revenue->id, debit: '1000.00', credit: '0'),
            new JournalLineData(accountId: (string) $equity->id, debit: '0', credit: '1000.00'),
        ]));
        $closing->update(['type' => 'year_end_closing']);
    }

    /**
     * @param  callable(): array<int, LineAllocationData>  $allocations
     * @return array<int, array<int, LineAllocationData>>
     */
    private function linesOf(JournalEntry $entry, callable $allocations): array
    {
        return TransactionLine::query()
            ->where('journal_entry_id', $entry->id)
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => $allocations()])
            ->all();
    }

    /** @return array<string, mixed> */
    private function fingerprint(): array
    {
        Cache::flush();
        $org = $this->organization->id;
        $reporting = app(ReportingService::class);

        return [
            'journal_entries' => DB::table('journal_entries')->where('organization_id', $org)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'transaction_lines' => DB::table('transaction_lines')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'vat_entries' => DB::table('vat_entries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'trial_balance' => json_encode(app(LedgerQueryService::class)->trialBalance($org)),
            'trial_balance_mid_year' => json_encode(app(LedgerQueryService::class)->trialBalance($org, '2026-06-30')),
            'profit_and_loss' => json_encode($reporting->profitAndLoss($org, '2026-01-01', '2026-12-31')),
            'balance_sheet' => json_encode($reporting->balanceSheet($org, '2026-12-31')),
            'vat_q1' => json_encode(app(VatReportService::class)->generateFresh($org, '2026-01-01', '2026-03-31')),
            'vat_year' => json_encode(app(VatReportService::class)->generateFresh($org, '2026-01-01', '2026-12-31')),
            'balances' => [
                'expense' => app(LedgerQueryService::class)->accountBalance($this->expense->id),
                'revenue' => app(LedgerQueryService::class)->accountBalance($this->revenue->id),
                'bank' => app(LedgerQueryService::class)->accountBalance($this->bank->id),
            ],
        ];
    }
}
