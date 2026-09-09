<?php

use App\Domains\Automation\Controllers\AutomationController;
use Illuminate\Support\Facades\Route;

// Automation module (feature-gated). Every route is read-only except the two
// that change what the system may do unattended, both of which are policed by
// AutomationPolicy and, for anything that writes, by the handler's own permission.
Route::middleware('feature:automation')->group(function () {
    Route::get('/automation', [AutomationController::class, 'index'])->name('automation.index');
    Route::put('/automation/settings', [AutomationController::class, 'update'])->name('automation.settings.update');
    Route::post('/automation/run', [AutomationController::class, 'trigger'])->name('automation.run');
});
