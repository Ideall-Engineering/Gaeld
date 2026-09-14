<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

class BudgetReadTest extends SecurityTestCase
{
    use WithAccountantApiModule;

    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAccountantApiModule();

        config(['features.api_access' => true, 'features.budgets' => true]);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);

        $revenue = Account::create(['organization_id' => $this->orgA->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        $expense = Account::create(['organization_id' => $this->orgA->id, 'code' => '6000', 'name' => 'Office', 'type' => AccountType::Expense->value]);

        Budget::create(['organization_id' => $this->orgA->id, 'account_id' => $revenue->id, 'fiscal_year' => 2026, 'monthly_amount' => '5000.00']);
        Budget::create(['organization_id' => $this->orgA->id, 'account_id' => $expense->id, 'fiscal_year' => 2026, 'monthly_amount' => '1200.00']);
        Budget::create(['organization_id' => $this->orgA->id, 'account_id' => $revenue->id, 'fiscal_year' => 2025, 'monthly_amount' => '4000.00']);
    }

    protected function tearDown(): void
    {
        $this->tearDownAccountantApiModule();
        parent::tearDown();
    }

    public function test_the_list_returns_every_budget_of_the_organization(): void
    {
        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_the_list_can_be_filtered_by_fiscal_year(): void
    {
        $response = $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets?fiscal_year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $budget) {
            $this->assertSame(2026, $budget['fiscal_year']);
        }
    }

    public function test_a_single_budget_is_addressed_by_account_code_and_fiscal_year(): void
    {
        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/3000/2026')
            ->assertOk()
            ->assertJsonPath('data.account_code', '3000')
            ->assertJsonPath('data.account_name', 'Revenue')
            ->assertJsonPath('data.fiscal_year', 2026)
            ->assertJsonPath('data.monthly_amount', '5000.00')
            ->assertJsonPath('data.annual_amount', '60000.00');
    }

    public function test_a_year_without_a_budget_is_a_404(): void
    {
        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/3000/2024')
            ->assertNotFound();
    }

    public function test_an_unknown_account_code_is_a_404(): void
    {
        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/9999/2026')
            ->assertNotFound();
    }
}
