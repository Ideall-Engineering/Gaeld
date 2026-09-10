<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Accountant API Module Routes
|--------------------------------------------------------------------------
|
| Registered under the same middleware stack as the core /api/v1 routes
| (see routes/api.php: auth:sanctum, api-org, idempotency, activity log,
| feature flag, throttling). Route::getRoutes() already carries the core
| routes when this file loads, so RouteCollisionGuard can check every
| path/name declared below against them.
|
| The five journal-correction endpoints (plan.md "API-Vertrag") are added
| in Phase 4; this file intentionally registers nothing yet so Phase 2 can
| land, boot, and be tested on its own first.
|
*/

Route::middleware(['auth:sanctum', 'api-org', 'feature:api_access', 'throttle:api'])
    ->prefix('v1')
    ->name('api.accountant-api.')
    ->group(function () {
        // Phase 4 adds the journal-correction routes here.
    });
