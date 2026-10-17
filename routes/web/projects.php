<?php

use App\Domains\Projects\Controllers\AllocationReviewController;
use App\Domains\Projects\Controllers\JournalAllocationController;
use App\Domains\Projects\Controllers\ProjectController;
use App\Domains\Projects\Controllers\ProjectReportController;
use App\Http\Middleware\EnsureProjectsModule;
use Illuminate\Support\Facades\Route;

// Projects and line allocations (spec 009), only for organizations that
// switched the module on.
Route::middleware(EnsureProjectsModule::class)->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/allocation-reviews', [AllocationReviewController::class, 'index'])->name('projects.allocation-reviews.index');
    Route::post('/projects/allocation-reviews/{allocationReview}/dismiss', [AllocationReviewController::class, 'dismiss'])->name('projects.allocation-reviews.dismiss');
    Route::get('/projects/{project}/report', [ProjectReportController::class, 'show'])->name('projects.report');
    Route::get('/projects/{project}/report/export/{format}', [ProjectReportController::class, 'export'])->name('projects.report.export');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::put('/accounting/journal-entries/{journalEntry}/allocations', [JournalAllocationController::class, 'update'])->name('accounting.journal-entries.allocations.update');
    Route::post('/accounting/journal-entry-allocations/bulk', [JournalAllocationController::class, 'bulk'])->name('accounting.journal-entries.allocations.bulk');
});
