<?php

namespace Tests\Feature\Reporting;

use App\Domains\Accounting\Actions\YearEndClosingAction;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\StatementBasis;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Reporting\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Following a figure in the profit and loss statement to the postings it is
 * made of.
 *
 * The point of these tests is one promise: the statement's total is the figure
 * that was clicked. The two sides are computed by different code — the report
 * sums balances, the statement walks lines — so the agreement is asserted over
 * every account of the report rather than sampled, and in a closed year as well
 * as an open one.
 */
class ProfitAndLossDrilldownTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private const FROM = '2026-01-01';

    private const TO = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_every_account_of_an_open_year_adds_up_to_the_figure_that_was_clicked(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->postSale('2026-07-02', '1500.00');
        $this->postCost('2026-03-05', '1200.00', '5000');
        $this->postCost('2026-09-19', '80.50', '6500');

        $this->assertReportReconcilesWithStatements();
    }

    public function test_every_account_of_a_closed_year_still_adds_up(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->postCost('2026-03-05', '1200.00', '5000');
        $this->closeTheYear('2026-12-31', ['3000' => '4000.00', '5000' => '1200.00']);

        // A correction booked after the closing is what keeps these accounts in
        // the report at all — a fully closed account nets to zero and the report
        // drops it. So this is the shape the drill-down has to survive: a row
        // whose figure only comes out right if the closing entry is counted in.
        $this->postSale('2026-12-31', '500.00');
        $this->postCost('2026-12-31', '90.00', '5000');
        app(LedgerService::class)->flushCache($this->org->id);

        $this->assertReportReconcilesWithStatements();
    }

    public function test_a_closed_year_still_reports_what_it_traded(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->closeTheYear('2026-12-31', ['3000' => '4000.00']);

        $report = app(ReportingService::class)->profitAndLoss($this->org->id, self::FROM, self::TO);

        // The closing entry empties the revenue account by design. Counting it
        // into a statement of trading reported the year as all zeros, which is
        // what this report no longer does: what the year earned is what it
        // earned, whether or not the books have since been closed on it.
        $this->assertSame('4000.00', (string) collect($report['revenue'])->firstWhere('code', '3000')['balance']);
        $this->assertSame('4000.00', (string) $report['net_profit']);
    }

    public function test_closing_a_year_does_not_disturb_the_balance_sheet(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->closeTheYear('2026-12-31', ['3000' => '4000.00']);

        $sheet = app(ReportingService::class)->balanceSheet($this->org->id, self::TO);

        // The other half of the same decision. The balance sheet stays on the
        // ledger basis, so the closing entry keeps carrying the result into
        // equity exactly once — counted there and not also in a synthetic row.
        $this->assertSame(
            bcadd((string) $sheet['assets']['total'], '0', 2),
            bcadd((string) $sheet['liabilities']['total'], (string) $sheet['equity']['total'], 2),
            'Assets = liabilities + equity has to hold after a closing.',
        );
        $this->assertSame('4000.00', bcadd((string) $sheet['equity']['total'], '0', 2));
    }

    /**
     * The ledger basis keeps its own behaviour. No report reaches a statement
     * this way today — both entry points ask for the operational basis — but
     * the mode is public on the service, and a statement computed on it says
     * plainly which part of its total came from a closing rather than leaving
     * a reader to guess.
     */
    public function test_a_statement_on_the_ledger_basis_names_what_a_closing_did(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->closeTheYear('2026-12-31', ['3000' => '4000.00']);

        $statement = app(LedgerQueryService::class)->accountStatementForPeriod(
            $this->account('3000'),
            Carbon::parse(self::FROM),
            Carbon::parse(self::TO),
            StatementBasis::Ledger,
        );

        $this->assertSame('0.00', $statement['total'], 'The closing entry cancels the year out.');
        $this->assertSame('4000.00', $statement['operationalTotal'], 'What was actually earned stays visible.');
        $this->assertSame('-4000.00', $statement['structuralTotal'], 'And what cancelled it is named separately.');
        $this->assertTrue($statement['hasStructuralEntries']);
        $this->assertContains(true, array_column($statement['lines'], 'isStructural'), 'The closing line is marked.');
    }

    public function test_the_dashboard_basis_still_leaves_the_closing_out(): void
    {
        $this->postSale('2026-02-11', '4000.00');
        $this->closeTheYear('2026-02-28', ['3000' => '4000.00']);

        $statement = app(LedgerQueryService::class)->accountStatementForPeriod(
            $this->account('3000'),
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-02-28'),
            StatementBasis::Operational,
        );

        $this->assertSame('4000.00', $statement['total'], 'A closing entry is not a month of trading.');
        $this->assertFalse($statement['hasStructuralEntries']);
        $this->assertNotContains(true, array_column($statement['lines'], 'isStructural'));
    }

    public function test_the_report_carries_the_uuid_each_row_is_followed_by(): void
    {
        $this->postSale('2026-02-11', '4000.00');

        $this->actAsOrg()
            ->get('/reports/profit-and-loss?from='.self::FROM.'&to='.self::TO)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.revenue.0.code', '3000')
                ->where('report.revenue.0.uuid', $this->account('3000')->uuid)
                ->etc());
    }

    public function test_the_statement_page_opens_on_the_reports_period_and_leads_back_to_it(): void
    {
        $this->postCost('2026-03-05', '1200.00', '5000');

        $this->actAsOrg()
            ->get("/accounting/accounts/{$this->account('5000')->uuid}/statement?from=".self::FROM.'&to='.self::TO.'&source=pnl')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/AccountStatement')
                ->where('mode', 'period')
                ->where('basis', ReportingService::PROFIT_AND_LOSS_BASIS->value)
                ->where('period.from', self::FROM)
                ->where('period.to', self::TO)
                ->where('backUrl', '/reports/profit-and-loss?from='.self::FROM.'&to='.self::TO)
                ->where('statement.total', '1200.00')
                ->etc());
    }

    public function test_the_dashboard_route_is_untouched_by_the_new_parameters(): void
    {
        $this->postCost('2026-03-05', '1200.00', '5000');

        $this->actAsOrg()
            ->get("/accounting/accounts/{$this->account('5000')->uuid}/statement?month=2026-03")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mode', 'month')
                ->where('basis', StatementBasis::Operational->value)
                ->where('backUrl', '/dashboard?month=2026-03#monthly-accounts')
                ->etc());
    }

    public function test_an_unknown_source_is_refused(): void
    {
        $this->actAsOrg()
            ->get("/accounting/accounts/{$this->account('5000')->uuid}/statement?from=".self::FROM.'&to='.self::TO.'&source=https://evil.example')
            ->assertSessionHasErrors('source');
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        $this->actAsOrg()
            ->get("/accounting/accounts/{$this->account('5000')->uuid}/statement?from=2026-12-31&to=2026-01-01&source=pnl")
            ->assertSessionHasErrors('to');
    }

    public function test_a_period_statement_does_not_admit_another_organizations_account_exists(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreign = Account::create([
            'organization_id' => $otherOrg->id,
            'code' => '5000',
            'name' => 'Wages elsewhere',
            'type' => AccountType::Expense->value,
        ]);

        $this->actAsOrg()
            ->get("/accounting/accounts/{$foreign->uuid}/statement?from=".self::FROM.'&to='.self::TO.'&source=pnl')
            ->assertNotFound();
    }

    /**
     * The promise, asserted over every row the report shows.
     */
    private function assertReportReconcilesWithStatements(): void
    {
        $report = app(ReportingService::class)->profitAndLoss($this->org->id, self::FROM, self::TO);
        $ledger = app(LedgerQueryService::class);

        $rows = [...$report['revenue'], ...$report['expenses']];
        $this->assertNotEmpty($rows, 'A reconciliation over no rows proves nothing.');

        foreach ($rows as $row) {
            $statement = $ledger->accountStatementForPeriod(
                Account::where('organization_id', $this->org->id)->where('uuid', $row['uuid'])->firstOrFail(),
                Carbon::parse(self::FROM),
                Carbon::parse(self::TO),
                ReportingService::PROFIT_AND_LOSS_BASIS,
            );

            $this->assertSame(
                (string) $row['balance'],
                $statement['total'],
                "Account {$row['code']} adds up to something other than the figure the report shows.",
            );
        }
    }

    /**
     * The shape {@see YearEndClosingAction} posts:
     * each revenue account debited by its balance, each expense account credited
     * by its, the difference landing on the result account.
     *
     * @param  array<string, numeric-string>  $balances  account code => balance
     */
    private function closeTheYear(string $closingDate, array $balances): void
    {
        $lines = [];
        $resultDebit = '0.00';
        $resultCredit = '0.00';

        foreach ($balances as $code => $balance) {
            $account = $this->account($code);
            $isRevenue = $account->type === AccountType::Revenue;

            $lines[] = $this->journalLine(
                $account,
                $isRevenue ? $balance : '0.00',
                $isRevenue ? '0.00' : $balance,
            );

            if ($isRevenue) {
                $resultCredit = bcadd($resultCredit, $balance, 2);
            } else {
                $resultDebit = bcadd($resultDebit, $balance, 2);
            }
        }

        $lines[] = $this->journalLine($this->account('2979'), $resultDebit, $resultCredit);

        $this->postJournalEntry($closingDate, $lines, 'CLOSE-'.$closingDate);
        $this->markLatestEntryAs('year_end_closing', $closingDate);
    }

    private function postSale(string $date, string $amount): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account('1020'), $amount, '0.00'),
            $this->journalLine($this->account('3000'), '0.00', $amount),
        ], 'SALE-'.$date);
    }

    private function postCost(string $date, string $amount, string $code): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account($code), $amount, '0.00'),
            $this->journalLine($this->account('1020'), '0.00', $amount),
        ], 'COST-'.$date.'-'.$code);
    }

    private function markLatestEntryAs(string $type, string $date): void
    {
        JournalEntry::where('organization_id', $this->org->id)
            ->where('date', $date)
            ->orderByDesc('id')
            ->first()
            ->update(['type' => $type]);

        app(LedgerService::class)->flushCache($this->org->id);
    }

    private function account(string $code): Account
    {
        $definitions = [
            '1020' => ['Bank', AccountType::Asset],
            '2979' => ['Retained result', AccountType::Equity],
            '3000' => ['Sales', AccountType::Revenue],
            '5000' => ['Wages', AccountType::Expense],
            '6500' => ['Administration', AccountType::Expense],
        ];

        [$name, $type] = $definitions[$code];

        return Account::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => $code],
            ['name' => $name, 'type' => $type->value],
        );
    }
}
