<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

/**
 * Budget mutations carry no `Idempotency-Key` requirement and are not run
 * through HandleApiIdempotency at all — unlike the journal-correction
 * endpoints, where the key is mandatory.
 *
 * Reason: `PUT`/`DELETE` against the natural key `(account_code,
 * fiscal_year)` are idempotent by construction. Replaying one can never
 * create a second row or a second ledger effect, so there is nothing a
 * reservation would protect. The middleware's automatic fallback key would
 * in fact break them — it is built from the route *name* plus a body hash
 * and never sees the concrete path parameters, so two budgets that differ
 * only in account code or year would collide on a single key.
 *
 * These tests pin that behaviour down, including the part that would
 * silently regress if the middleware were ever added to the group.
 */
class BudgetIdempotencyTest extends SecurityTestCase
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
    }

    protected function tearDown(): void
    {
        $this->tearDownAccountantApiModule();
        parent::tearDown();
    }

    public function test_a_mutation_without_a_key_is_accepted(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '5000.00'])
            ->assertCreated();
    }

    public function test_repeating_the_identical_call_yields_the_identical_result(): void
    {
        $first = $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '5000.00'])
            ->assertCreated();

        $second = $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '5000.00'])
            ->assertOk();

        $this->assertSame($first->json('data.monthly_amount'), $second->json('data.monthly_amount'));
        $this->assertDatabaseCount('budgets', 1);
    }

    /**
     * The case the middleware's fallback key would get wrong: same body,
     * different path. Both must be written.
     */
    public function test_two_accounts_with_the_same_amount_are_two_separate_budgets(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '1000.00'])
            ->assertCreated();

        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/6000/2026', ['monthly_amount' => '1000.00'])
            ->assertCreated();

        $this->assertDatabaseCount('budgets', 2);
    }

    /** Same account and body, different year — also two separate budgets. */
    public function test_two_years_with_the_same_amount_are_two_separate_budgets(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '1000.00'])
            ->assertCreated();

        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2027', ['monthly_amount' => '1000.00'])
            ->assertCreated();

        $this->assertDatabaseCount('budgets', 2);
    }

    public function test_a_supplied_idempotency_key_never_suppresses_a_real_write(): void
    {
        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'budget-same-key')
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '1000.00'])
            ->assertCreated();

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'budget-same-key')
            ->putJson('/api/v1/budgets/6000/2026', ['monthly_amount' => '1000.00'])
            ->assertCreated();

        $this->assertDatabaseCount('budgets', 2);
    }

    public function test_deleting_twice_reports_the_second_call_as_missing(): void
    {
        $this->withToken($this->tokenA)
            ->putJson('/api/v1/budgets/3000/2026', ['monthly_amount' => '1000.00'])
            ->assertCreated();

        $this->withToken($this->tokenA)
            ->deleteJson('/api/v1/budgets/3000/2026')
            ->assertNoContent();

        $this->withToken($this->tokenA)
            ->deleteJson('/api/v1/budgets/3000/2026')
            ->assertNotFound();
    }
}
