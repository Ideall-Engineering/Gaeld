<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TaxDeclaration;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The figures a tax return is built from.
 *
 * These come from the controller's own query rather than from ReportingService,
 * which is how they went on reporting a closed year as nothing earned long after
 * the profit and loss statement had stopped doing so. The tests below hold both
 * halves of the rule in place: what the year traded is counted, what the closing
 * did to the books is not, and the balance-sheet categories are untouched by the
 * distinction.
 */
class TaxDeclarationSummaryTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        config()->set('features.tax_declaration', true);
    }

    public function test_a_closed_year_still_reports_what_it_traded(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->postCost('2026-03-05', '1200.00');
        $this->closeTheYear('2026-12-31');

        $data = $this->summaryFor(2026);

        $this->assertSame(4000.0, (float) $data['revenue'], 'The year earned this, closed books or not.');
        $this->assertSame(1200.0, (float) $data['expenses']);
        $this->assertSame(2800.0, (float) $data['profit']);
        $this->assertSame(2800.0, (float) $data['net_result']);
    }

    public function test_an_open_year_is_unaffected(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->postCost('2026-03-05', '1200.00');

        $data = $this->summaryFor(2026);

        $this->assertSame(4000.0, (float) $data['revenue']);
        $this->assertSame(1200.0, (float) $data['expenses']);
        $this->assertSame(2800.0, (float) $data['profit']);
    }

    public function test_vat_survives_the_year_being_closed(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->recordVat('2026-02-11', VatEntryType::Output, base: '4000.00', vat: '324.00');
        $this->closeTheYear('2026-12-31');

        // The closing entry touches no VAT entry, so the settlement was never
        // the part that collapsed. Held here anyway: both halves of this file's
        // subject have to survive a closing together.
        $this->assertSame(324.0, (float) $this->summaryFor(2026)['vat_payable']);
        $this->assertSame(4000.0, (float) $this->summaryFor(2026)['revenue']);
    }

    public function test_the_closing_still_counts_towards_equity(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->postCost('2026-03-05', '1200.00');
        $this->closeTheYear('2026-12-31');

        // The other half of the rule: the closing is what moves a result into
        // equity, so leaving it out of the balance-sheet categories would lose
        // the result entirely. 4000 earned less 1200 spent is what lands there.
        $this->assertSame(2800.0, (float) $this->summaryFor(2026)['equity']);
    }

    public function test_vat_comes_from_the_settlement_and_deducts_input_tax(): void
    {
        $this->postSale('2026-02-11', '100000.00');
        $this->recordVat('2026-02-11', VatEntryType::Output, base: '100000.00', vat: '8100.00');
        $this->recordVat('2026-03-05', VatEntryType::Input, base: '40000.00', vat: '3200.00');

        $data = $this->summaryFor(2026);

        // The old estimate said 8100 — output tax with no account of what had
        // been reclaimed. What is owed is the difference.
        $this->assertSame(8100.0, (float) $data['vat_output']);
        $this->assertSame(3200.0, (float) $data['vat_input']);
        $this->assertSame(4900.0, (float) $data['vat_payable']);
        $this->assertSame(0.0, (float) $data['vat_credit']);
        $this->assertArrayNotHasKey('vat_payable_estimate', $data);
    }

    public function test_more_input_than_output_is_a_credit_not_a_liability(): void
    {
        $this->postSale('2026-02-11', '20000.00');
        $this->recordVat('2026-02-11', VatEntryType::Output, base: '20000.00', vat: '1620.00');
        $this->recordVat('2026-03-05', VatEntryType::Input, base: '60000.00', vat: '4860.00');

        $data = $this->summaryFor(2026);

        // max(0, revenue * 0.081) could never express this: it reported a
        // liability where the organization is owed money.
        $this->assertSame(0.0, (float) $data['vat_payable']);
        $this->assertSame(3240.0, (float) $data['vat_credit']);
    }

    public function test_a_reduced_rate_is_not_charged_at_the_standard_one(): void
    {
        $this->postSale('2026-02-11', '50000.00');
        $this->recordVat('2026-02-11', VatEntryType::Output, base: '50000.00', vat: '1300.00', rateCode: 'REDUCED', rate: '2.60');

        // The estimate applied 8.1% to all revenue and would have said 4050.
        $this->assertSame(1300.0, (float) $this->summaryFor(2026)['vat_output']);
    }

    public function test_an_organization_that_records_no_vat_is_shown_none(): void
    {
        $this->postSale('2026-02-11', '100000.00');

        $data = $this->summaryFor(2026);

        // Not zero: zero reads as a figure that was worked out. Nothing recorded
        // means there is nothing to state, and an association below the
        // registration threshold should not be handed a liability.
        $this->assertArrayNotHasKey('vat_payable', $data);
        $this->assertArrayNotHasKey('vat_output', $data);
        $this->assertArrayNotHasKey('vat_payable_estimate', $data);
        $this->assertSame(100000.0, (float) $data['revenue'], 'The rest of the summary is unaffected.');
    }

    public function test_a_finalised_declaration_keeps_the_figures_it_was_finalised_with(): void
    {
        $this->postSale('2026-02-11', '4000.00');

        $declaration = TaxDeclaration::create([
            'organization_id' => $this->org->id,
            'fiscal_year' => 2026,
            'canton' => 'SO',
        ]);

        $this->actAsOrg()->get("/accounting/tax-declarations/{$declaration->getRouteKey()}")->assertOk();
        $this->actAsOrg()->post("/accounting/tax-declarations/{$declaration->getRouteKey()}/finalize")->assertRedirect();

        $this->postSale('2026-03-01', '9999.00');
        app(LedgerService::class)->flushCache($this->org->id);

        $this->actAsOrg()->get("/accounting/tax-declarations/{$declaration->getRouteKey()}")->assertOk();

        $this->assertSame(4000.0, (float) $declaration->refresh()->data['revenue'], 'A finalised return is not rewritten under the reader.');
    }

    public function test_a_july_to_june_fiscal_year_is_summarised_over_its_own_months(): void
    {
        FiscalYear::create([
            'organization_id' => $this->org->id,
            'name' => '2026/27',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'status' => FiscalYearStatus::Operative,
        ]);

        // Inside the fiscal year, outside the calendar one.
        $this->postSale('2027-03-15', '5000.00');
        // Inside the calendar year 2026, outside this fiscal year.
        $this->postSale('2026-02-11', '4000.00');

        $data = $this->summaryFor(2026);

        $this->assertSame(5000.0, (float) $data['revenue'], 'The year the organization actually runs, not January to December.');
    }

    public function test_without_a_fiscal_year_on_record_the_calendar_year_still_applies(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->postSale('2027-03-15', '5000.00');

        // The legacy fallback in FiscalYearService, which is what an installation
        // that never recorded fiscal years keeps getting.
        $this->assertSame(4000.0, (float) $this->summaryFor(2026)['revenue']);
    }

    /**
     * @return array<string, float>
     */
    private function summaryFor(int $fiscalYear): array
    {
        // firstOrCreate so a test may read the summary more than once: one
        // declaration per year and canton is all the schema allows.
        $declaration = TaxDeclaration::firstOrCreate([
            'organization_id' => $this->org->id,
            'fiscal_year' => $fiscalYear,
            'canton' => 'SO',
        ]);

        $this->actAsOrg()->get("/accounting/tax-declarations/{$declaration->getRouteKey()}")->assertOk();

        return $declaration->refresh()->data;
    }

    /**
     * A VAT entry on its own posting, the way the invoicing and expense services
     * record one when a booking carries VAT.
     */
    private function recordVat(
        string $date,
        VatEntryType $type,
        string $base,
        string $vat,
        string $rateCode = 'NORMAL',
        string $rate = '8.10',
    ): void {
        $vatRate = VatRate::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => $rateCode],
            ['name' => $rateCode, 'rate' => $rate],
        );

        $entry = JournalEntry::where('organization_id', $this->org->id)
            ->where('date', $date)
            ->orderByDesc('id')
            ->first();

        if ($entry === null) {
            $this->postJournalEntry($date, [
                $this->journalLine($this->account('1020'), $base, '0.00'),
                $this->journalLine($this->account('3000'), '0.00', $base),
            ], 'VAT-'.$type->value.'-'.$date);

            $entry = JournalEntry::where('organization_id', $this->org->id)
                ->where('date', $date)
                ->orderByDesc('id')
                ->firstOrFail();
        }

        VatEntry::create([
            'journal_entry_id' => $entry->id,
            'vat_rate_id' => $vatRate->id,
            'base_amount' => $base,
            'vat_amount' => $vat,
            'type' => $type,
        ]);
    }

    private function closeTheYear(string $closingDate): void
    {
        $this->postJournalEntry($closingDate, [
            $this->journalLine($this->account('3000'), '4000.00', '0.00'),
            $this->journalLine($this->account('5000'), '0.00', '1200.00'),
            $this->journalLine($this->account('2979'), '1200.00', '4000.00'),
        ], 'CLOSE-'.$closingDate);

        JournalEntry::where('organization_id', $this->org->id)
            ->where('date', $closingDate)
            ->orderByDesc('id')
            ->first()
            ->update(['type' => 'year_end_closing']);

        app(LedgerService::class)->flushCache($this->org->id);
    }

    private function postSale(string $date, string $amount): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account('1020'), $amount, '0.00'),
            $this->journalLine($this->account('3000'), '0.00', $amount),
        ], 'SALE-'.$date);
    }

    private function postCost(string $date, string $amount): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account('5000'), $amount, '0.00'),
            $this->journalLine($this->account('1020'), '0.00', $amount),
        ], 'COST-'.$date);
    }

    private function account(string $code): Account
    {
        $definitions = [
            '1020' => ['Bank', AccountType::Asset],
            '2979' => ['Retained result', AccountType::Equity],
            '3000' => ['Sales', AccountType::Revenue],
            '5000' => ['Wages', AccountType::Expense],
        ];

        [$name, $type] = $definitions[$code];

        return Account::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => $code],
            ['name' => $name, 'type' => $type->value],
        );
    }
}
