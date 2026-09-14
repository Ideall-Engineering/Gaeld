<?php

namespace Plugins\AccountantApi\CoreBridge;

use App\Domains\Accounting\Actions\DeleteBudgetAction;
use App\Domains\Accounting\Actions\UpsertBudgetAction;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Api\Services\AccountCodeResolver;
use App\Domains\Reporting\Services\ReportingService;
use App\Domains\Users\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

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
        private ReportingService $reportingService,
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

    /**
     * Budget versus actual for a period, projected out of the very report the
     * web profit and loss statement renders.
     *
     * Deliberately runs no arithmetic of its own. ReportingService already
     * computes the prorated target, the variance and the variance percentage
     * per account; recomputing any of it here would create a second source of
     * truth that could drift from what a user sees on screen.
     *
     * @return array{rows: array<int, array<string, mixed>>, months: int, totals: array<string, string>|null}
     */
    public function variance(string $organizationId, string $fromDate, string $toDate): array
    {
        $report = $this->reportingService->profitAndLoss($organizationId, $fromDate, $toDate);

        $rows = [];
        foreach (['revenue', 'expenses'] as $section) {
            foreach ($report[$section] ?? [] as $account) {
                // No target for this account: it has nothing to say here.
                if (($account['budget_amount'] ?? null) === null) {
                    continue;
                }

                $rows[] = [
                    'account_code' => $account['code'],
                    'account_name' => $account['name'],
                    'account_type' => $section === 'revenue' ? 'revenue' : 'expense',
                    'actual_amount' => (string) $account['balance'],
                    'budget_amount' => (string) $account['budget_amount'],
                    'variance' => (string) $account['budget_variance'],
                    'variance_percentage' => $account['budget_variance_percentage'] !== null
                        ? (string) $account['budget_variance_percentage']
                        : null,
                ];
            }
        }

        $budget = $report['budget'] ?? null;

        return [
            'rows' => $rows,
            'months' => (int) ($budget['months'] ?? $this->monthsBetween($fromDate, $toDate)),
            'totals' => $budget === null ? null : [
                'budget_revenue' => (string) $budget['total_revenue'],
                'budget_expenses' => (string) $budget['total_expenses'],
                'budget_net_profit' => (string) $budget['net_profit'],
            ],
        ];
    }

    private function monthsBetween(string $fromDate, string $toDate): int
    {
        return (int) Carbon::parse($fromDate)->diffInMonths(Carbon::parse($toDate)) + 1;
    }
}
