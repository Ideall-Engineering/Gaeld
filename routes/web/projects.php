<?php

use App\Domains\Projects\Controllers\AllocationReviewController;
use App\Domains\Projects\Controllers\BudgetGroupController;
use App\Domains\Projects\Controllers\InvoiceProjectController;
use App\Domains\Projects\Controllers\JournalAllocationController;
use App\Domains\Projects\Controllers\ProjectController;
use App\Domains\Projects\Controllers\ProjectReportController;
use App\Domains\Projects\Controllers\UnallocatedLinesController;
use App\Http\Middleware\EnsureProjectsModule;
use Illuminate\Support\Facades\Route;

// Projects and line allocations (spec 009), only for organizations that
// switched the module on.
Route::middleware(EnsureProjectsModule::class)->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/export', [ProjectController::class, 'export'])->name('projects.export');
    Route::get('/projects/plan', [BudgetGroupController::class, 'index'])->name('projects.plan');
    Route::get('/projects/plan/export', [BudgetGroupController::class, 'export'])->name('projects.plan.export');
    Route::post('/projects/plan', [BudgetGroupController::class, 'store'])->name('projects.plan.store');
    Route::post('/projects/plan/copy-previous', [BudgetGroupController::class, 'copyPrevious'])->name('projects.plan.copy');
    Route::put('/projects/plan/{budgetGroup}', [BudgetGroupController::class, 'update'])->name('projects.plan.update');
    Route::delete('/projects/plan/{budgetGroup}', [BudgetGroupController::class, 'destroy'])->name('projects.plan.destroy');
    Route::get('/projects/unallocated', [UnallocatedLinesController::class, 'index'])->name('projects.unallocated');
    Route::post('/projects/unallocated', [UnallocatedLinesController::class, 'apply'])->name('projects.unallocated.apply');
    Route::get('/projects/allocation-reviews', [AllocationReviewController::class, 'index'])->name('projects.allocation-reviews.index');
    Route::post('/projects/allocation-reviews/{allocationReview}/dismiss', [AllocationReviewController::class, 'dismiss'])->name('projects.allocation-reviews.dismiss');
    Route::get('/projects/{project}/report', [ProjectReportController::class, 'show'])->name('projects.report');
    Route::get('/projects/{project}/report/export/{format}', [ProjectReportController::class, 'export'])->name('projects.report.export');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::put('/invoices/{invoice}/project', [InvoiceProjectController::class, 'update'])->name('invoices.project.update');
    Route::put('/accounting/journal-entries/{journalEntry}/allocations', [JournalAllocationController::class, 'update'])->name('accounting.journal-entries.allocations.update');
    Route::post('/accounting/journal-entry-allocations/bulk', [JournalAllocationController::class, 'bulk'])->name('accounting.journal-entries.allocations.bulk');
});
