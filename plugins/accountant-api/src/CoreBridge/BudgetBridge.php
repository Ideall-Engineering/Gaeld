<?php

namespace Plugins\AccountantApi\CoreBridge;

use App\Domains\Accounting\Actions\DeleteBudgetAction;
use App\Domains\Accounting\Actions\UpsertBudgetAction;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Api\Services\AccountCodeResolver;
use App\Domains\Users\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The only class in the budget slice that imports core Accounting/Api
 * classes directly — same boundary rule as {@see JournalCorrectionBridge}
 * (see tests/Feature/Plugins/AccountantApiModuleBoundaryTest.php).
 *
 * Budgets are addressed by their natural key `(account_code, fiscal_year)`
 * rather than by id: `budgets` has no uuid column, and the core
 * `/api/v1/accounts` resource only ever exposes the account uuid, so the
 * integer `account_id` the table needs is not obtainable through the API at
 * all. The natural key is already a unique index, which additionally makes
 * PUT and DELETE idempotent by construction.
 */
final class BudgetBridge
{
    public function __construct(
        private AccountCodeResolver $accountCodeResolver,
        private UpsertBudgetAction $upsertAction,
        private DeleteBudgetAction $deleteAction,
    ) {}

    /**
     * @throws ModelNotFoundException When the code is unknown in this organization or the account is inactive
     */
    public function resolveAccountOrFail(string $organizationId, string $accountCode): Account
    {
        try {
            return $this->accountCodeResolver->resolve($organizationId, $accountCode);
        } catch (DomainException $exception) {
            // An account code is a path segment here, so an unknown or
            // inactive code is a missing resource, not a payload error.
            throw new ModelNotFoundException($exception->getMessage());
        }
    }

    /** @return LengthAwarePaginator<int, Budget> */
    public function paginate(string $organizationId, ?int $fiscalYear, int $perPage): LengthAwarePaginator
    {
        return Budget::query()
            ->where('organization_id', $organizationId)
            ->when($fiscalYear !== null, fn ($query) => $query->where('fiscal_year', $fiscalYear))
            ->with('account:id,code,name,type')
            ->orderBy('fiscal_year')
            ->orderBy('account_id')
            ->paginate($perPage);
    }

    /** @throws ModelNotFoundException */
    public function findOrFail(string $organizationId, Account $account, int $fiscalYear): Budget
    {
        return Budget::query()
            ->where('organization_id', $organizationId)
            ->where('account_id', $account->id)
            ->where('fiscal_year', $fiscalYear)
            ->with('account:id,code,name,type')
            ->firstOrFail();
    }

    public function exists(string $organizationId, Account $account, int $fiscalYear): bool
    {
        return Budget::query()
            ->where('organization_id', $organizationId)
            ->where('account_id', $account->id)
            ->where('fiscal_year', $fiscalYear)
            ->exists();
    }

    public function upsert(string $organizationId, Account $account, int $fiscalYear, string $monthlyAmount): Budget
    {
        $budget = $this->upsertAction->execute($organizationId, $account, $fiscalYear, $monthlyAmount);

        return $budget->load('account:id,code,name,type');
    }

    public function delete(Budget $budget): void
    {
        $this->deleteAction->execute($budget);
    }

    public function userCan(User $user, string $ability, Budget|string $target): bool
    {
        return $user->can($ability, $target);
    }

    public function budgetModelClass(): string
    {
        return Budget::class;
    }
}
