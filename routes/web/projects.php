<?php

use App\Domains\Projects\Controllers\ProjectController;
use App\Http\Middleware\EnsureProjectsModule;
use Illuminate\Support\Facades\Route;

// Projects and line allocations (spec 009), only for organizations that
// switched the module on.
Route::middleware(EnsureProjectsModule::class)->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
});
