<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

/**
 * The budget-versus-actual read endpoint. It runs no arithmetic of its own:
 * it projects ReportingService::profitAndLoss(), the very source the web
 * profit and loss statement uses, so the two can never drift apart.
 */
class BudgetVarianceTest extends SecurityTestCase
{
    use WithAccountantApiModule;

    private string $tokenA;

    private Account $revenue;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAccountantApiModule();

        config(['features.api_access' => true, 'features.budgets' => true]);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);

        $bank = Account::create(['organization_id' => $this->orgA->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $this->revenue = Account::create(['organization_id' => $this->orgA->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        $this->expense = Account::create(['organization_id' => $this->orgA->id, 'code' => '6000', 'name' => 'Office', 'type' => AccountType::Expense->value]);

        // Actuals: 9000 revenue, 1200 expense, both inside 2026.
        $entry = JournalEntry::create([
            'organization_id' => $this->orgA->id,
            'date' => '2026-02-01',
            'reference' => 'VAR-1',
            'is_posted' => true,
        ]);
        $entry->lines()->createMany([
            ['account_id' => $bank->id, 'debit' => '9000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenue->id, 'debit' => '0.00', 'credit' => '9000.00'],
        ]);

        $entry2 = JournalEntry::create([
            'organization_id' => $this->orgA->id,
            'date' => '2026-02-02',
            'reference' => 'VAR-2',
            'is_posted' => true,
        ]);
        $entry2->lines()->createMany([
            ['account_id' => $this->expense->id, 'debit' => '1200.00', 'credit' => '0.00'],
            ['account_id' => $bank->id, 'debit' => '0.00', 'credit' => '1200.00'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAccountantApiModule();
        parent::tearDown();
    }

    private function setBudget(Account $account, string $monthly, int $year = 2026): void
    {
        Budget::create([
            'organization_id' => $this->orgA->id,
            'account_id' => $account->id,
            'fiscal_year' => $year,
            'monthly_amount' => $monthly,
        ]);
    }

    public function test_a_year_without_any_budget_returns_empty_rows_not_a_404(): void
    {
        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.fiscal_year', 2026);
    }

    public function test_the_variance_is_reported_per_account(): void
    {
        $this->setBudget($this->revenue, '1000.00');   // 12000 over the year
        $this->setBudget($this->expense, '100.00');    // 1200 over the year

        $response = $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('account_code');

        $this->assertSame('9000.00', $rows['3000']['actual_amount']);
        $this->assertSame('12000.00', $rows['3000']['budget_amount']);
        $this->assertSame('-3000.00', $rows['3000']['variance']);
        $this->assertSame('-25.00', $rows['3000']['variance_percentage']);
        $this->assertSame('revenue', $rows['3000']['account_type']);

        $this->assertSame('1200.00', $rows['6000']['actual_amount']);
        $this->assertSame('1200.00', $rows['6000']['budget_amount']);
        $this->assertSame('0.00', $rows['6000']['variance']);
        $this->assertSame('expense', $rows['6000']['account_type']);
    }

    public function test_accounts_without_a_target_are_left_out(): void
    {
        $this->setBudget($this->revenue, '1000.00');

        $response = $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('3000', $response->json('data.0.account_code'));
    }

    public function test_a_partial_period_prorates_the_target(): void
    {
        $this->setBudget($this->revenue, '1000.00');

        $response = $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026&from=2026-01-01&to=2026-03-31')
            ->assertOk();

        // Three months at 1000, not the full year.
        $this->assertSame('3000.00', $response->json('data.0.budget_amount'));
        $this->assertSame('3', (string) $response->json('meta.months'));
    }

    public function test_totals_are_reported_alongside_the_rows(): void
    {
        $this->setBudget($this->revenue, '1000.00');
        $this->setBudget($this->expense, '100.00');

        $response = $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026')
            ->assertOk();

        $this->assertSame('12000.00', $response->json('meta.totals.budget_revenue'));
        $this->assertSame('1200.00', $response->json('meta.totals.budget_expenses'));
    }

    public function test_a_period_outside_the_fiscal_year_is_rejected(): void
    {
        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026&from=2025-01-01&to=2026-03-31')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    public function test_the_fiscal_year_is_required(): void
    {
        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance')
            ->assertStatus(422)
            ->assertJsonValidationErrors('fiscal_year');
    }

    public function test_another_organizations_figures_are_never_returned(): void
    {
        app(CurrentOrganization::class)->set($this->orgB);
        $accountB = Account::create(['organization_id' => $this->orgB->id, 'code' => '3000', 'name' => 'Revenue B', 'type' => AccountType::Revenue->value]);
        Budget::create(['organization_id' => $this->orgB->id, 'account_id' => $accountB->id, 'fiscal_year' => 2026, 'monthly_amount' => '9999.00']);
        app(CurrentOrganization::class)->set($this->orgA);

        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_token_without_the_view_ability_is_forbidden(): void
    {
        app(CurrentOrganization::class)->set($this->orgA);
        $limited = $this->ownerA->createToken('limited', ['invoicing.view']);
        $limited->accessToken->update(['organization_id' => $this->orgA->id, 'type' => TokenType::Personal]);
        $this->app['auth']->forgetGuards();

        $this->withToken($limited->plainTextToken)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026')
            ->assertForbidden();
    }

    public function test_the_route_is_closed_when_the_budgets_feature_is_disabled(): void
    {
        config(['features.budgets' => false]);

        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/variance?fiscal_year=2026')
            ->assertForbidden();
    }
}
