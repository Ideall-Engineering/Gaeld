<?php

namespace Tests\Feature\Migration;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Migration\DTOs\ImportResult;
use App\Domains\Migration\Enums\DataType;
use App\Domains\Migration\Importers\JournalEntryImporter;
use App\Domains\Migration\Parsers\BananaParser;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The Banana journal import end to end: parse the export, group the rows into
 * bookings, resolve the VAT codes and post them so the VAT return can be built
 * from the result.
 */
class BananaJournalImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        $accounts = [
            ['1020', AccountType::Asset], ['1091', AccountType::Asset],
            ['1170', AccountType::Asset], ['2200', AccountType::Liability],
            ['2202', AccountType::Liability], ['2270', AccountType::Liability],
            ['3100', AccountType::Revenue], ['3200', AccountType::Revenue],
            ['5600', AccountType::Expense], ['6033', AccountType::Expense],
            ['6034', AccountType::Expense], ['6050', AccountType::Expense],
            ['6051', AccountType::Expense],
        ];

        foreach ($accounts as [$code, $type]) {
            Account::create([
                'organization_id' => $this->organization->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type->value,
            ]);
        }

        foreach ([['NORMAL', '8.10', true], ['EXEMPT', '0.00', false]] as [$code, $rate, $default]) {
            VatRate::create([
                'organization_id' => $this->organization->id,
                'name' => $code,
                'code' => $code,
                'rate' => $rate,
                'is_default' => $default,
                'is_active' => true,
            ]);
        }
    }

    private function parseFixture(): Collection
    {
        $file = UploadedFile::fake()->createWithContent(
            'journal.tsv',
            (string) file_get_contents(base_path('tests/fixtures/banana-journal-2026.tsv')),
        );

        return app(BananaParser::class)->parse($file, DataType::JournalEntries);
    }

    private function import(): ImportResult
    {
        return app(JournalEntryImporter::class)->import($this->parseFixture(), $this->organization);
    }

    // ──────────────────────────────────────────────────────────────
    //  Parsing
    // ──────────────────────────────────────────────────────────────

    public function test_rows_sharing_a_document_number_become_one_entry(): void
    {
        $rows = $this->parseFixture();
        $payroll = $rows->firstWhere('reference', 'BAN-20260518-1062');

        $this->assertNotNull($payroll);
        // Three source rows without a VAT code, each a debit/credit pair.
        $this->assertCount(6, $payroll->lines);
        $this->assertSame('2026-05-18', $payroll->date);
    }

    public function test_the_same_document_number_in_two_months_stays_two_entries(): void
    {
        $references = $this->parseFixture()->pluck('reference')->all();

        $this->assertContains('BAN-20260526-1090', $references);
        $this->assertContains('BAN-20260626-1090', $references);
    }

    public function test_apostrophes_and_two_digit_years_are_normalised(): void
    {
        $row = $this->parseFixture()->firstWhere('reference', 'BAN-20260107-1002');

        $this->assertSame('2026-01-07', $row->date);
        $this->assertSame('3675.40', $row->lines[0]['gross']);
    }

    public function test_a_row_without_an_amount_is_marked_invalid(): void
    {
        $row = $this->parseFixture()->firstWhere('reference', 'BAN-20260630-1111');

        $this->assertNotNull($row);
        $this->assertFalse($row->isValid());
    }

    // ──────────────────────────────────────────────────────────────
    //  Importing
    // ──────────────────────────────────────────────────────────────

    public function test_entries_are_posted_not_drafted(): void
    {
        $this->import();

        $entry = JournalEntry::where('reference', 'BAN-20260105-1069')->firstOrFail();
        $this->assertTrue($entry->is_posted);
    }

    public function test_m81_becomes_input_vat(): void
    {
        $this->import();

        $entry = JournalEntry::where('reference', 'BAN-20260105-1069')->firstOrFail();
        $vatEntry = VatEntry::where('journal_entry_id', $entry->id)->sole();

        $this->assertSame(VatEntryType::Input, $vatEntry->type);
        $this->assertSame('0.70', (string) $vatEntry->base_amount);
        $this->assertSame('0.06', (string) $vatEntry->vat_amount);
    }

    public function test_i81_becomes_the_investment_figure(): void
    {
        $this->import();

        $entry = JournalEntry::where('reference', 'BAN-20260211-1018')->firstOrFail();
        $vatEntry = VatEntry::where('journal_entry_id', $entry->id)->sole();

        $this->assertSame(VatEntryType::InputInvestment, $vatEntry->type);
        $this->assertSame('80.39', (string) $vatEntry->base_amount);
        $this->assertSame('6.51', (string) $vatEntry->vat_amount);
    }

    public function test_v81_splits_the_gross_turnover_on_the_credit_side(): void
    {
        $this->import();

        $entry = JournalEntry::where('reference', 'BAN-20260107-1001')->firstOrFail();
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($line) => $line->account->code);

        $this->assertSame('259.44', (string) $lines['1020']->debit);
        $this->assertSame('240.00', (string) $lines['3100']->credit);
        $this->assertSame('19.44', (string) $lines['2200']->credit);
    }

    public function test_v0_turnover_reaches_chiffre_200_and_defaults_to_chiffre_220(): void
    {
        $this->import();

        $entry = JournalEntry::where('reference', 'BAN-20260430-1051')->firstOrFail();
        $vatEntry = VatEntry::where('journal_entry_id', $entry->id)->sole();

        $this->assertSame(VatEntryType::Output, $vatEntry->type);
        $this->assertSame('18322.95', (string) $vatEntry->base_amount);
        $this->assertSame('0.00', (string) $vatEntry->vat_amount);
        $this->assertSame('220', $vatEntry->figure);
    }

    public function test_a_row_without_a_code_creates_no_vat_entry(): void
    {
        $this->import();

        $entry = JournalEntry::where('reference', 'BAN-20260413-1084')->firstOrFail();

        $this->assertSame(0, VatEntry::where('journal_entry_id', $entry->id)->count());
        $this->assertCount(2, $entry->lines);
    }

    public function test_z0_is_imported_without_a_vat_entry(): void
    {
        $this->import();

        $entry = JournalEntry::where('reference', 'BAN-20260615-1099')->firstOrFail();

        $this->assertSame(0, VatEntry::where('journal_entry_id', $entry->id)->count());
        $this->assertCount(2, $entry->lines);
    }

    public function test_a_second_run_creates_no_duplicates(): void
    {
        $first = $this->import();
        $countAfterFirst = JournalEntry::count();

        $second = $this->import();

        $this->assertSame($countAfterFirst, JournalEntry::count());
        $this->assertSame(0, $second->importedCount);
        $this->assertGreaterThan(0, $first->importedCount);
    }

    // ──────────────────────────────────────────────────────────────
    //  The number that matters
    // ──────────────────────────────────────────────────────────────

    public function test_the_vat_report_reproduces_the_figures_of_the_source_journal(): void
    {
        $this->import();

        $report = app(VatReportService::class)->generate(
            $this->organization->id,
            '2026-01-01',
            '2026-12-31',
        );

        // 240.00 + 3'400.00 turnover at 8.1 %, plus 18'322.95 zero-rated.
        $this->assertSame('294.84', $report['total_output_vat']);
        // M81 on 0.70 plus M0 (none): only the Google Cloud centimes.
        $this->assertSame('0.06', $report['input_vat']);
        // I81 on 80.39.
        $this->assertSame('6.51', $report['input_investment_vat']);
        $this->assertSame('21962.95', $report['total_revenue']);
        $this->assertSame('18322.95', $report['deductions_by_figure']['220']);
        $this->assertSame('3640.00', $report['total_taxable']);
        $this->assertSame('288.27', $report['net_vat']);
    }
}
