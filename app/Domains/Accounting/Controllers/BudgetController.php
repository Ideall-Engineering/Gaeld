<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Actions\DeleteBudgetAction;
use App\Domains\Accounting\Actions\UpsertBudgetAction;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Budget;
use App\Domains\Accounting\Requests\StoreBudgetRequest;
use App\Domains\Accounting\Requests\UpdateBudgetRequest;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages monthly budget targets per account and fiscal year.
 */
class BudgetController extends Controller
{
    public function index(Request $request, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', Budget::class);

        $year = (int) $request->input('year', now()->year);

        $budgets = Budget::with('account:id,code,name,type')
            ->forYear($year)
            ->paginate(25)
            ->withQueryString();

        $accounts = Account::where('is_active', true)
            ->whereIn('type', ['Revenue', 'Expense'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $currentYear = now()->year;
        $fiscalYears = collect(range($currentYear, $currentYear - 4))
            ->map(fn (int $y) => ['value' => $y, 'label' => (string) $y])
            ->values()
            ->all();

        return Inertia::render('Accounting/Budgets/Index', [
            'budgets' => $budgets,
            'accounts' => $accounts,
            'fiscalYears' => $fiscalYears,
            'selectedYear' => $year,
        ]);
    }

    public function store(
        StoreBudgetRequest $request,
        CurrentOrganization $currentOrg,
        UpsertBudgetAction $upsertBudget,
    ): RedirectResponse {
        $this->authorize('create', Budget::class);

        $validated = $request->validated();

        $upsertBudget->execute(
            $currentOrg->id(),
            Account::whereKey($validated['account_id'])->firstOrFail(),
            (int) $validated['fiscal_year'],
            (string) $validated['monthly_amount'],
        );

        return redirect()->route('accounting.budgets', ['year' => $validated['fiscal_year']])
            ->with('success', __('app.budget_saved'));
    }

    public function destroy(Budget $budget, DeleteBudgetAction $deleteBudget): RedirectResponse
    {
        $this->authorize('delete', $budget);

        $year = $budget->fiscal_year;
        $deleteBudget->execute($budget);

        return redirect()->route('accounting.budgets', ['year' => $year])
            ->with('success', __('app.budget_deleted'));
    }

    public function update(
        UpdateBudgetRequest $request,
        Budget $budget,
        UpsertBudgetAction $upsertBudget,
    ): RedirectResponse {
        $this->authorize('update', $budget);

        $validated = $request->validated();

        $upsertBudget->execute(
            $budget->organization_id,
            $budget->account,
            $budget->fiscal_year,
            (string) $validated['monthly_amount'],
        );

        return redirect()->route('accounting.budgets', ['year' => $budget->fiscal_year])
            ->with('success', __('app.budget_updated'));
    }
}
