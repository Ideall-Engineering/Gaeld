<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TaxDeclaration;
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

    public function test_the_vat_estimate_follows_the_traded_revenue(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->closeTheYear('2026-12-31');

        // Previously zero, because the revenue it is a percentage of was zero.
        $this->assertSame(324.0, (float) $this->summaryFor(2026)['vat_payable_estimate']);
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

    /**
     * @return array<string, float>
     */
    private function summaryFor(int $fiscalYear): array
    {
        $declaration = TaxDeclaration::create([
            'organization_id' => $this->org->id,
            'fiscal_year' => $fiscalYear,
            'canton' => 'SO',
        ]);

        $this->actAsOrg()->get("/accounting/tax-declarations/{$declaration->getRouteKey()}")->assertOk();

        return $declaration->refresh()->data;
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
