<?php

use App\Http\Middleware\LogOrgTokenActivity;
use Illuminate\Support\Facades\Route;
use Plugins\AccountantApi\Controllers\BudgetController;
use Plugins\AccountantApi\Controllers\JournalCorrectionController;

/*
|--------------------------------------------------------------------------
| Accountant API Module Routes
|--------------------------------------------------------------------------
|
| Each group below restates the core /api/v1 middleware stack explicitly
| (see routes/api.php), because loadRoutesFrom() inherits nothing from it.
| Note that the correction group deliberately omits HandleApiIdempotency —
| JournalCorrectionController reserves and completes idempotency itself,
| tied to the actual domain result. Groups that do not do that must list
| 'api-idempotency' themselves, as the budget group does.
|
| Route::getRoutes() already carries the core routes when this file loads,
| so RouteCollisionGuard can check every path/name declared below against
| them (App\Providers\PluginServiceProvider).
|
| The five journal-correction endpoints (plan.md "API-Vertrag").
| HandleApiIdempotency treats every 'api.accountant-api.*' route the same
| way it treats 'api.journal-entries.*': it does nothing and
| JournalCorrectionController reserves/completes idempotency itself, tied to
| the actual domain result — see HandleApiIdempotency::isHandledByDomainController().
|
*/

Route::middleware(['auth:sanctum', 'api-org', 'feature:api_access', 'throttle:api'])
    ->prefix('api/v1')
    ->name('api.accountant-api.')
    ->group(function () {
        Route::post('/journal-entries/{original}/corrections', [JournalCorrectionController::class, 'store'])
            ->name('journal-entries.corrections.store');
        Route::get('/journal-corrections/{correction}', [JournalCorrectionController::class, 'show'])
            ->name('journal-corrections.show');
        Route::put('/journal-corrections/{correction}/replacement', [JournalCorrectionController::class, 'updateReplacement'])
            ->name('journal-corrections.replacement.update');
        Route::post('/journal-corrections/{correction}/post', [JournalCorrectionController::class, 'post'])
            ->name('journal-corrections.post');
        Route::delete('/journal-corrections/{correction}', [JournalCorrectionController::class, 'destroy'])
            ->name('journal-corrections.destroy');
    });

/*
| Budgets (plan.md Etappe 8). Addressed by the natural key
| (account_code, fiscal_year) — see BudgetBridge for why there is no id.
| Gated by feature:budgets in addition to feature:api_access, matching the
| web routes in routes/web/accounting.php.
|
| These routes deliberately omit HandleApiIdempotency. PUT and DELETE on a
| natural key are idempotent by construction, so there is nothing for it to
| protect — and its automatic fallback key would actively break them:
| ApiIdempotencyService builds that key from the route *name* plus a body
| hash, never the concrete path parameters. Core routes escape this only
| because their parameters are implicit model bindings, which
| fallbackReference() resolves to a model id. Module routes use plain
| strings by design (module boundary), so PUT /budgets/3000/2026 and
| PUT /budgets/6000/2026 with the same amount would collide on one key and
| the second would replay the first instead of writing. A repeated DELETE
| would likewise replay 204 without deleting anything.
*/
Route::middleware(['auth:sanctum', 'api-org', LogOrgTokenActivity::class, 'feature:api_access', 'feature:budgets', 'throttle:api'])
    ->prefix('api/v1')
    ->name('api.accountant-api.')
    ->group(function () {
        Route::get('/budgets', [BudgetController::class, 'index'])
            ->name('budgets.index');
        Route::get('/budgets/{account_code}/{fiscal_year}', [BudgetController::class, 'show'])
            ->whereNumber('fiscal_year')
            ->name('budgets.show');
        Route::put('/budgets/{account_code}/{fiscal_year}', [BudgetController::class, 'put'])
            ->whereNumber('fiscal_year')
            ->name('budgets.put');
        Route::delete('/budgets/{account_code}/{fiscal_year}', [BudgetController::class, 'destroy'])
            ->whereNumber('fiscal_year')
            ->name('budgets.destroy');
    });
