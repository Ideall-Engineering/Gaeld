<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

/**
 * Kept beside the other module feature tests rather than under
 * `plugins/accountant-api/tests/Security/`, matching the decision already
 * taken for JournalCorrectionSecurityTest (T041).
 */
class BudgetSecurityTest extends SecurityTestCase
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
    }

    protected function tearDown(): void
    {
        $this->tearDownAccountantApiModule();
        parent::tearDown();
    }

    /**
     * Builds org B's side through the domain layer rather than a second
     * `withToken()` call — same reason as JournalCorrectionSecurityTest.
     */
    private function seedBudgetForAnotherOrganization(): void
    {
        app(CurrentOrganization::class)->set($this->orgB);

        $account = Account::create(['organization_id' => $this->orgB->id, 'code' => '3000', 'name' => 'Revenue B', 'type' => AccountType::Revenue->value]);
        Budget::create(['organization_id' => $this->orgB->id, 'account_id' => $account->id, 'fiscal_year' => 2026, 'monthly_amount' => '9999.00']);

        app(CurrentOrganization::class)->set($this->orgA);
    }

    public function test_another_organizations_budget_is_never_visible(): void
    {
        $this->seedBudgetForAnotherOrganization();

        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($this->tokenA)
            ->getJson('/api/v1/budgets/3000/2026')
            ->assertNotFound();
    }

    public function test_writing_never_reaches_another_organizations_budget(): void
    {
        $this->seedBudgetForAnotherOrganization();

        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '1.00'])
            ->assertCreated();

        // Org A got its own row; org B's untouched.
        $this->assertDatabaseCount('budgets', 2);
        $this->assertDatabaseHas('budgets', ['organization_id' => $this->orgB->id, 'monthly_amount' => '9999.00']);
        $this->assertDatabaseHas('budgets', ['organization_id' => $this->orgA->id, 'monthly_amount' => '1.00']);
    }

    public function test_a_read_only_token_cannot_write(): void
    {
        app(CurrentOrganization::class)->set($this->orgA);
        $limited = $this->ownerA->createToken('limited', ['accounting.view']);
        $limited->accessToken->update(['organization_id' => $this->orgA->id, 'type' => TokenType::Personal]);
        $this->app['auth']->forgetGuards();

        $this->withToken($limited->plainTextToken)
            ->getJson('/api/v1/budgets')
            ->assertOk();

        $this->withToken($limited->plainTextToken)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '1.00'])
            ->assertForbidden();

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_all_routes_are_closed_when_the_budgets_feature_is_disabled(): void
    {
        config(['features.budgets' => false]);

        $this->withToken($this->tokenA)->getJson('/api/v1/budgets')->assertForbidden();
        $this->withToken($this->tokenA)->getJson('/api/v1/budgets/3000/2026')->assertForbidden();
        $this->withToken($this->tokenA)->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '1.00'])->assertForbidden();
        $this->withToken($this->tokenA)->deleteJson('/api/v1/budgets/3000/2026')->assertForbidden();

        $this->assertDatabaseCount('budgets', 0);
    }
}
