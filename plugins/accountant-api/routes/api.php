<?php

use Illuminate\Support\Facades\Route;
use Plugins\AccountantApi\Controllers\JournalCorrectionController;

/*
|--------------------------------------------------------------------------
| Accountant API Module Routes
|--------------------------------------------------------------------------
|
| Registered under the same middleware stack as the core /api/v1 routes
| (auth:sanctum, api-org, idempotency, activity log, feature flag,
| throttling — see routes/api.php). Route::getRoutes() already carries the
| core routes when this file loads, so RouteCollisionGuard can check every
| path/name declared below against them (App\Providers\PluginServiceProvider).
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
