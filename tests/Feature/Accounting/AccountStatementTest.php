<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The dashboard's month view and the account statement it drills into.
 */
class AccountStatementTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_monthly_account_totals_name_every_account_that_moved(): void
    {
        $this->postCost('2026-09-03', '1200.00', '5000');
        $this->postCost('2026-09-20', '300.50', '5000');
        $this->postCost('2026-09-11', '80.00', '6500');
        $this->postCost('2026-08-04', '999.00', '6500');
        $this->postSale('2026-09-15', '4000.00');

        $totals = app(LedgerQueryService::class)->monthlyAccountTotals($this->org->id, 2026);

        $september = collect($totals['expenses'][9]);

        $this->assertSame(
            [['5000', '1500.50'], ['6500', '80.00']],
            $september->map(fn (array $row) => [$row['code'], $row['amount']])->all(),
            'Both accounts appear, each summed over the month and sorted by size.',
        );
        $this->assertSame(
            $this->account('5000')->uuid,
            $september->firstWhere('code', '5000')['uuid'],
            'The public uuid is what the dashboard links with.',
        );

        $this->assertSame('4000.00', collect($totals['revenue'][9])->firstWhere('code', '3000')['amount']);
        $this->assertSame('999.00', collect($totals['expenses'][8])->firstWhere('code', '6500')['amount']);
        $this->assertSame([], $totals['expenses'][7], 'A month without bookings stays empty.');
    }

    public function test_the_statement_adds_the_month_up_and_carries_the_balance(): void
    {
        $this->postCost('2026-08-10', '500.00', '5000');
        $this->postCost('2026-09-05', '1200.00', '5000');
        $this->postCost('2026-09-25', '300.00', '5000');
        $this->postCost('2026-10-01', '77.00', '5000');

        $statement = app(LedgerQueryService::class)
            ->accountStatementForMonth($this->account('5000'), 2026, 9);

        $this->assertSame('500.00', $statement['openingBalance'], 'August, since the fiscal year began.');
        $this->assertSame('1500.00', $statement['total']);
        $this->assertSame('2000.00', $statement['closingBalance']);
        $this->assertCount(2, $statement['lines'], 'October is somebody else\'s month.');

        $this->assertSame(['1700.00', '2000.00'], [
            $statement['lines'][0]['balance'],
            $statement['lines'][1]['balance'],
        ], 'The running balance starts from what the month inherited.');
        $this->assertSame(
            ['1020 Bank'],
            $statement['lines'][0]['counterAccounts'],
            'The other side of the booking is what makes the line readable.',
        );
    }

    public function test_the_statement_leaves_out_what_the_dashboard_leaves_out(): void
    {
        $this->postCost('2026-09-05', '1200.00', '5000');
        $this->postCost('2026-09-06', '800.00', '5000');
        $this->markLatestEntryAs('year_end_closing');

        $statement = app(LedgerQueryService::class)
            ->accountStatementForMonth($this->account('5000'), 2026, 9);

        $this->assertSame('1200.00', $statement['total'], 'A closing entry is not a month of spending.');
        $this->assertCount(1, $statement['lines']);
    }

    public function test_the_dashboard_carries_the_month_view(): void
    {
        $this->postCost('2026-09-05', '1200.00', '5000');

        $this->actAsOrg()->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('displayMonth', 9)
                ->where('monthlyAccounts.expenses.9.0.code', '5000')
                ->where('monthlyAccounts.expenses.9.0.amount', '1200.00')
                ->etc());
    }

    public function test_the_statement_page_renders_for_a_month(): void
    {
        $this->postCost('2026-09-05', '1200.00', '5000');

        $this->actAsOrg()
            ->get("/accounting/accounts/{$this->account('5000')->uuid}/statement?month=2026-09")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/AccountStatement')
                ->where('month', '2026-09')
                ->where('previousMonth', '2026-08')
                ->where('nextMonth', '2026-10')
                ->where('account.code', '5000')
                ->where('statement.total', '1200.00')
                ->etc());
    }

    public function test_the_statement_page_refuses_a_malformed_month(): void
    {
        $this->actAsOrg()
            ->get("/accounting/accounts/{$this->account('5000')->uuid}/statement?month=September")
            ->assertSessionHasErrors('month');
    }

    public function test_the_statement_page_does_not_admit_another_organizations_account_exists(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreign = Account::create([
            'organization_id' => $otherOrg->id,
            'code' => '5000',
            'name' => 'Wages elsewhere',
            'type' => AccountType::Expense->value,
        ]);

        // 404, not 403: the organization scope in BelongsToOrganization makes a
        // foreign id look absent rather than forbidden, so a probe learns
        // nothing from the status code.
        $this->actAsOrg()
            ->get("/accounting/accounts/{$foreign->uuid}/statement?month=2026-09")
            ->assertNotFound();
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

    private function markLatestEntryAs(string $type): void
    {
        JournalEntry::where('organization_id', $this->org->id)
            ->orderByDesc('date')
            ->first()
            ->update(['type' => $type]);

        app(LedgerService::class)->flushCache($this->org->id);
    }

    private function account(string $code): Account
    {
        $definitions = [
            '1020' => ['Bank', AccountType::Asset],
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
