<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

class BudgetWriteTest extends SecurityTestCase
{
    use WithAccountantApiModule;

    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAccountantApiModule();

        config(['features.api_access' => true, 'features.budgets' => true]);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);

        Account::create(['organization_id' => $this->orgA->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        Account::create(['organization_id' => $this->orgA->id, 'code' => '6000', 'name' => 'Office', 'type' => AccountType::Expense->value]);
        Account::create(['organization_id' => $this->orgA->id, 'code' => '6001', 'name' => 'Retired', 'type' => AccountType::Expense->value, 'is_active' => false]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAccountantApiModule();
        parent::tearDown();
    }

    public function test_setting_a_new_budget_creates_it(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '5000.00'])
            ->assertCreated()
            ->assertJsonPath('data.account_code', '3000')
            ->assertJsonPath('data.monthly_amount', '5000.00');

        $this->assertDatabaseCount('budgets', 1);
    }

    public function test_setting_an_existing_budget_updates_it_without_creating_a_second_row(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '5000.00'])
            ->assertCreated();

        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '5500.00'])
            ->assertOk()
            ->assertJsonPath('data.monthly_amount', '5500.00');

        $this->assertDatabaseCount('budgets', 1);
        $this->assertDatabaseHas('budgets', ['fiscal_year' => 2026, 'monthly_amount' => '5500.00']);
    }

    public function test_repeating_the_identical_call_is_stable(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->withToken($this->tokenA)
                ->putJson('/api/v1/budgets/6000/2026', ['monthly_amount' => '1200.00'])
                ->assertSuccessful();
        }

        $this->assertDatabaseCount('budgets', 1);
    }

    public function test_a_budget_can_be_deleted_and_deleting_again_is_a_404(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '5000.00'])
            ->assertCreated();

        $this->withToken($this->tokenA)
            ->deleteJson('/api/v1/budgets/3000/2026')
            ->assertNoContent();

        $this->assertDatabaseCount('budgets', 0);

        $this->withToken($this->tokenA)
            ->deleteJson('/api/v1/budgets/3000/2026')
            ->assertNotFound();
    }

    public function test_an_unknown_account_code_is_a_404(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/9999/2026', ['monthly_amount' => '100.00'])
            ->assertNotFound();

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_an_inactive_account_is_a_404(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/6001/2026', ['monthly_amount' => '100.00'])
            ->assertNotFound();

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_a_fiscal_year_outside_the_supported_range_is_rejected(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/1999', ['monthly_amount' => '100.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fiscal_year');

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_a_negative_or_missing_amount_is_rejected(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '-1.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monthly_amount');

        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monthly_amount');

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_the_web_ui_sees_the_same_row(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '5000.00'])
            ->assertCreated();

        $budget = Budget::withoutGlobalScopes()->sole();

        $this->assertSame($this->orgA->id, $budget->organization_id);
        $this->assertSame(2026, $budget->fiscal_year);
        $this->assertSame('5000.00', $budget->monthly_amount);
    }
}
