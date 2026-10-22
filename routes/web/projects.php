<?php

use App\Domains\Projects\Controllers\AcquisitionController;
use App\Domains\Projects\Controllers\AllocationReviewController;
use App\Domains\Projects\Controllers\BudgetGroupController;
use App\Domains\Projects\Controllers\InternalSettlementController;
use App\Domains\Projects\Controllers\InvoiceProjectController;
use App\Domains\Projects\Controllers\JournalAllocationController;
use App\Domains\Projects\Controllers\PlannedExpenseController;
use App\Domains\Projects\Controllers\ProjectController;
use App\Domains\Projects\Controllers\ProjectJournalController;
use App\Domains\Projects\Controllers\ProjectReportController;
use App\Domains\Projects\Controllers\SteeringController;
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
    Route::get('/projects/plan/{budgetGroup}/lines', [BudgetGroupController::class, 'lines'])->name('projects.plan.lines');
    Route::put('/projects/plan/{budgetGroup}', [BudgetGroupController::class, 'update'])->name('projects.plan.update');
    Route::delete('/projects/plan/{budgetGroup}', [BudgetGroupController::class, 'destroy'])->name('projects.plan.destroy');
    Route::get('/projects/acquisitions', [AcquisitionController::class, 'index'])->name('projects.acquisitions');
    Route::get('/projects/acquisitions/export', [AcquisitionController::class, 'export'])->name('projects.acquisitions.export');
    Route::post('/projects/acquisitions', [AcquisitionController::class, 'store'])->name('projects.acquisitions.store');
    Route::put('/projects/acquisitions/{acquisition}', [AcquisitionController::class, 'update'])->name('projects.acquisitions.update');
    Route::delete('/projects/acquisitions/{acquisition}', [AcquisitionController::class, 'destroy'])->name('projects.acquisitions.destroy');
    Route::get('/projects/steering', [SteeringController::class, 'index'])->name('projects.steering');
    Route::get('/projects/general-costs', [SteeringController::class, 'generalCosts'])->name('projects.general-costs');
    Route::post('/projects/settlements', [InternalSettlementController::class, 'store'])->name('projects.settlements.store');
    Route::put('/projects/settlements/{internalSettlement}', [InternalSettlementController::class, 'update'])->name('projects.settlements.update');
    Route::delete('/projects/settlements/{internalSettlement}', [InternalSettlementController::class, 'destroy'])->name('projects.settlements.destroy');
    Route::get('/projects/steering/export/{format}', [SteeringController::class, 'export'])->name('projects.steering.export');
    // Spec 012: single expected expenses entered by hand.
    Route::post('/projects/steering/planned-expenses', [PlannedExpenseController::class, 'store'])->name('projects.planned-expenses.store');
    Route::put('/projects/steering/planned-expenses/{plannedExpense}', [PlannedExpenseController::class, 'update'])->name('projects.planned-expenses.update');
    Route::delete('/projects/steering/planned-expenses/{plannedExpense}', [PlannedExpenseController::class, 'destroy'])->name('projects.planned-expenses.destroy');
    Route::post('/projects/steering/items', [SteeringController::class, 'storeItem'])->name('projects.steering.items.store');
    Route::delete('/projects/steering/items/{steeringItem}', [SteeringController::class, 'destroyItem'])->name('projects.steering.items.destroy');
    Route::get('/projects/journal', [ProjectJournalController::class, 'index'])->name('projects.journal');
    Route::get('/projects/unallocated', [UnallocatedLinesController::class, 'index'])->name('projects.unallocated');
    Route::post('/projects/unallocated', [UnallocatedLinesController::class, 'apply'])->name('projects.unallocated.apply');
    Route::post('/projects/unallocated/general', [UnallocatedLinesController::class, 'general'])->name('projects.unallocated.general');
    Route::get('/projects/allocation-reviews', [AllocationReviewController::class, 'index'])->name('projects.allocation-reviews.index');
    Route::post('/projects/allocation-reviews/{allocationReview}/dismiss', [AllocationReviewController::class, 'dismiss'])->name('projects.allocation-reviews.dismiss');
    Route::get('/projects/{project}/report', [ProjectReportController::class, 'show'])->name('projects.report');
    Route::get('/projects/{project}/report/export/{format}', [ProjectReportController::class, 'export'])->name('projects.report.export');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::put('/projects/{project}/payment-date', [ProjectController::class, 'paymentDate'])->name('projects.payment-date');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::put('/invoices/{invoice}/project', [InvoiceProjectController::class, 'update'])->name('invoices.project.update');
    Route::post('/invoices/{invoice}/job', [InvoiceProjectController::class, 'createJob'])->name('invoices.job.store');
    Route::put('/accounting/journal-entries/{journalEntry}/allocations', [JournalAllocationController::class, 'update'])->name('accounting.journal-entries.allocations.update');
    Route::post('/accounting/journal-entry-allocations/bulk', [JournalAllocationController::class, 'bulk'])->name('accounting.journal-entries.allocations.bulk');
});
