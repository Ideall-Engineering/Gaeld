<?php

namespace Plugins\AccountantApi\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Plugins\AccountantApi\CoreBridge\BudgetBridge;
use Plugins\AccountantApi\Requests\PutBudgetRequest;
use Plugins\AccountantApi\Resources\BudgetResource;

/**
 * Monthly budget targets per account and fiscal year (plan.md Etappe 8,
 * "Budgets"). Calls only {@see BudgetBridge} — no core Domain-Action, model,
 * or exception is referenced here directly, and route parameters are plain
 * strings rather than implicit model bindings, for the same boundary reason
 * as {@see JournalCorrectionController}.
 *
 * Idempotency is handled by HandleApiIdempotency, not by this controller:
 * PUT and DELETE against the natural key are idempotent by construction, so
 * there is no domain result a reservation would need to be tied to.
 */
class BudgetController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private BudgetBridge $bridge,
        private CurrentOrganization $currentOrganization,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', $this->bridge->budgetModelClass());

        $fiscalYear = $request->filled('fiscal_year') ? (int) $request->input('fiscal_year') : null;
        $perPage = min((int) $request->input('per_page', 25) ?: 25, self::MAX_PER_PAGE);

        return BudgetResource::collection(
            $this->bridge->paginate($this->currentOrganization->id(), $fiscalYear, $perPage)
        );
    }

    public function show(Request $request, string $account_code, string $fiscal_year): BudgetResource
    {
        $this->authorize('viewAny', $this->bridge->budgetModelClass());

        $organizationId = $this->currentOrganization->id();

        try {
            $account = $this->bridge->resolveAccountOrFail($organizationId, $account_code);
            $budget = $this->bridge->findOrFail($organizationId, $account, (int) $fiscal_year);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return new BudgetResource($budget);
    }

    public function put(PutBudgetRequest $request, string $account_code, string $fiscal_year): JsonResponse
    {
        $organizationId = $this->currentOrganization->id();

        try {
            $account = $this->bridge->resolveAccountOrFail($organizationId, $account_code);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $year = (int) $fiscal_year;
        $existed = $this->bridge->exists($organizationId, $account, $year);

        // Creating and updating are separate abilities in BudgetPolicy, so
        // the check has to know which of the two this call actually is.
        $this->authorize(
            $existed ? 'update' : 'create',
            $existed
                ? $this->bridge->findOrFail($organizationId, $account, $year)
                : $this->bridge->budgetModelClass(),
        );

        $budget = $this->bridge->upsert(
            $organizationId,
            $account,
            $year,
            (string) $request->validated('monthly_amount'),
        );

        return (new BudgetResource($budget))
            ->response()
            ->setStatusCode($existed ? 200 : 201);
    }

    public function destroy(Request $request, string $account_code, string $fiscal_year): JsonResponse
    {
        $organizationId = $this->currentOrganization->id();

        try {
            $account = $this->bridge->resolveAccountOrFail($organizationId, $account_code);
            $budget = $this->bridge->findOrFail($organizationId, $account, (int) $fiscal_year);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $this->authorize('delete', $budget);

        $this->bridge->delete($budget);

        return response()->json(null, 204);
    }
}
