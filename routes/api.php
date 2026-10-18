<?php

use App\Domains\Api\Controllers\AccountApiController;
use App\Domains\Api\Controllers\ApiInfoController;
use App\Domains\Api\Controllers\ApiTokenController;
use App\Domains\Api\Controllers\BankAccountApiController;
use App\Domains\Api\Controllers\BankImportApiController;
use App\Domains\Api\Controllers\ContactApiController;
use App\Domains\Api\Controllers\ExpenseApiController;
use App\Domains\Api\Controllers\InvoiceApiController;
use App\Domains\Api\Controllers\InvoicePdfApiController;
use App\Domains\Api\Controllers\InvoicePdfPreviewApiController;
use App\Domains\Api\Controllers\JournalEntryApiController;
use App\Domains\Api\Controllers\JournalEntryRevisionApiController;
use App\Domains\Api\Controllers\OrgTokenController;
use App\Domains\Api\Controllers\QuoteApiController;
use App\Domains\Api\Controllers\WebhookApiController;
use App\Domains\Projects\Controllers\AcquisitionApiController;
use App\Domains\Projects\Controllers\BudgetGroupApiController;
use App\Domains\Projects\Controllers\JournalAllocationApiController;
use App\Domains\Projects\Controllers\ProjectApiController;
use App\Domains\Projects\Controllers\SteeringController;
use App\Http\Middleware\Api\HandleApiIdempotency;
use App\Http\Middleware\EnsureProjectsModule;
use App\Http\Middleware\LogOrgTokenActivity;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1 and protected by Sanctum auth
| + organization resolution via the API token's organization_id.
| Access requires the FEATURE_API_ACCESS flag to be enabled.
|
| Two token types:
|  - Personal tokens: created by users, scoped to one org, requires membership
|  - Organization tokens: managed by admins, survive user removal
|
*/

// Public API root — version & status (no auth required)
Route::prefix('v1')->group(function () {
    Route::get('/', ApiInfoController::class)->name('api.info');
});

Route::middleware(['auth:sanctum', 'api-org', HandleApiIdempotency::class, LogOrgTokenActivity::class, 'feature:api_access', 'throttle:api'])->prefix('v1')->group(function () {

    // Personal token management (user's own tokens)
    Route::get('/tokens', [ApiTokenController::class, 'index'])->name('api.tokens.index');
    Route::post('/tokens', [ApiTokenController::class, 'store'])->name('api.tokens.store');
    Route::delete('/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api.tokens.destroy');

    // Organization token management (admin-managed, org-level tokens)
    Route::get('/org-tokens', [OrgTokenController::class, 'index'])->name('api.org-tokens.index');
    Route::post('/org-tokens', [OrgTokenController::class, 'store'])->name('api.org-tokens.store');
    Route::delete('/org-tokens/{token}', [OrgTokenController::class, 'destroy'])->name('api.org-tokens.destroy');

    // Reference data
    Route::get('/meta/abilities', [ApiTokenController::class, 'abilities'])->name('api.meta.abilities');
    Route::get('/meta/webhook-events', [ApiTokenController::class, 'webhookEvents'])->name('api.meta.webhook-events');

    // Invoices
    Route::apiResource('invoices', InvoiceApiController::class)->names([
        'index' => 'api.invoices.index',
        'show' => 'api.invoices.show',
        'store' => 'api.invoices.store',
        'update' => 'api.invoices.update',
        'destroy' => 'api.invoices.destroy',
    ]);

    // Invoice lifecycle operations
    // Quotes (offers)
    Route::apiResource('quotes', QuoteApiController::class)->names([
        'index' => 'api.quotes.index',
        'show' => 'api.quotes.show',
        'store' => 'api.quotes.store',
        'update' => 'api.quotes.update',
        'destroy' => 'api.quotes.destroy',
    ]);
    Route::post('/quotes/{quote}/send', [QuoteApiController::class, 'send'])->name('api.quotes.send');
    Route::post('/quotes/{quote}/accept', [QuoteApiController::class, 'accept'])->name('api.quotes.accept');
    Route::post('/quotes/{quote}/decline', [QuoteApiController::class, 'decline'])->name('api.quotes.decline');
    Route::post('/quotes/{quote}/reopen', [QuoteApiController::class, 'reopen'])->name('api.quotes.reopen');
    Route::post('/quotes/{quote}/convert', [QuoteApiController::class, 'convert'])->name('api.quotes.convert');
    Route::get('/quotes/{quote}/pdf', [QuoteApiController::class, 'pdf'])->name('api.quotes.pdf');
    Route::post('/invoices/{invoice}/finalize', [InvoiceApiController::class, 'finalize'])->name('api.invoices.finalize');
    Route::post('/invoices/{invoice}/cancel', [InvoiceApiController::class, 'cancel'])->name('api.invoices.cancel');
    Route::post('/invoices/{invoice}/record-payment', [InvoiceApiController::class, 'recordPayment'])->name('api.invoices.record-payment');
    Route::post('/invoices/{invoice}/send', [InvoiceApiController::class, 'send'])->name('api.invoices.send');
    Route::post('/invoices/{invoice}/reminder', [InvoiceApiController::class, 'reminder'])->name('api.invoices.reminder');
    Route::post('/invoices/{invoice}/credit-note', [InvoiceApiController::class, 'creditNote'])->name('api.invoices.credit-note');
    Route::get('/invoices/{invoice}/pdf', InvoicePdfApiController::class)->name('api.invoices.pdf');
    Route::get('/invoices/{invoice}/pdf/preview', InvoicePdfPreviewApiController::class)->name('api.invoices.pdf.preview');

    // Expenses
    Route::apiResource('expenses', ExpenseApiController::class)->names([
        'index' => 'api.expenses.index',
        'show' => 'api.expenses.show',
        'store' => 'api.expenses.store',
        'update' => 'api.expenses.update',
        'destroy' => 'api.expenses.destroy',
    ]);

    // Expense workflow operations
    Route::post('/expenses/{expense}/approve', [ExpenseApiController::class, 'approve'])->name('api.expenses.approve');
    Route::post('/expenses/{expense}/post-to-ledger', [ExpenseApiController::class, 'postToLedger'])->name('api.expenses.post-to-ledger');
    Route::post('/expenses/{expense}/cancel', [ExpenseApiController::class, 'cancel'])->name('api.expenses.cancel');

    // Contacts
    Route::apiResource('contacts', ContactApiController::class)->names([
        'index' => 'api.contacts.index',
        'show' => 'api.contacts.show',
        'store' => 'api.contacts.store',
        'update' => 'api.contacts.update',
        'destroy' => 'api.contacts.destroy',
    ]);
    Route::apiResource('customers', ContactApiController::class)
        ->parameters(['customers' => 'contact'])
        ->names([
            'index' => 'api.customers.index',
            'show' => 'api.customers.show',
            'store' => 'api.customers.store',
            'update' => 'api.customers.update',
            'destroy' => 'api.customers.destroy',
        ]);

    // Journal entries
    Route::get('/journal-entries', [JournalEntryApiController::class, 'index'])->name('api.journal-entries.index');
    Route::get('/journal-entries/{journalEntry}', [JournalEntryApiController::class, 'show'])->name('api.journal-entries.show');
    Route::post('/journal-entries', [JournalEntryApiController::class, 'store'])->name('api.journal-entries.store');
    Route::patch('/journal-entries/{journalEntry}', [JournalEntryApiController::class, 'update'])->name('api.journal-entries.update');
    Route::patch('/journal-entries/{journalEntry}/text', [JournalEntryApiController::class, 'text'])->name('api.journal-entries.text');
    Route::put('/journal-entries/{journalEntry}/document', [JournalEntryApiController::class, 'document'])->name('api.journal-entries.document');
    Route::post('/journal-entries/{journalEntry}/post', [JournalEntryApiController::class, 'post'])->name('api.journal-entries.post');
    Route::post('/journal-entries/{journalEntry}/reverse', [JournalEntryApiController::class, 'reverse'])->name('api.journal-entries.reverse');
    Route::delete('/journal-entries/{journalEntry}', [JournalEntryApiController::class, 'destroy'])->name('api.journal-entries.destroy');
    Route::get('/journal-entries/{journalEntry}/revisions', [JournalEntryRevisionApiController::class, 'forEntry'])->name('api.journal-entries.revisions');
    Route::get('/journal-entry-revisions', [JournalEntryRevisionApiController::class, 'index'])->name('api.journal-entry-revisions.index');

    // Projects and line allocations (spec 009), only where the module is on
    Route::middleware(EnsureProjectsModule::class)->group(function () {
        Route::get('/projects', [ProjectApiController::class, 'index'])->name('api.projects.index');
        Route::post('/projects', [ProjectApiController::class, 'store'])->name('api.projects.store');
        Route::get('/projects/{project}', [ProjectApiController::class, 'show'])->name('api.projects.show');
        Route::patch('/projects/{project}', [ProjectApiController::class, 'update'])->name('api.projects.update');
        Route::get('/projects/{project}/report', [ProjectApiController::class, 'report'])->name('api.projects.report');
        Route::get('/acquisitions', [AcquisitionApiController::class, 'index'])->name('api.acquisitions.index');
        Route::post('/acquisitions', [AcquisitionApiController::class, 'store'])->name('api.acquisitions.store');
        Route::get('/acquisitions/people', [AcquisitionApiController::class, 'people'])->name('api.acquisitions.people');
        Route::put('/projects/{project}/shares', [ProjectApiController::class, 'shares'])->name('api.projects.shares');
        Route::put('/budget-groups/{budgetGroup}/people', [BudgetGroupApiController::class, 'people'])->name('api.budget-groups.people');
        Route::get('/steering', [SteeringController::class, 'indexApi'])->name('api.steering.index');
        Route::post('/steering-items', [SteeringController::class, 'storeItemApi'])->name('api.steering-items.store');
        Route::get('/budget-groups', [BudgetGroupApiController::class, 'index'])->name('api.budget-groups.index');
        Route::post('/budget-groups', [BudgetGroupApiController::class, 'store'])->name('api.budget-groups.store');
        Route::put('/journal-entries/{journalEntry}/allocations', [JournalAllocationApiController::class, 'update'])->name('api.journal-entries.allocations');
    });

    // Accounts (read-only)
    Route::get('/accounts', [AccountApiController::class, 'index'])->name('api.accounts.index');
    Route::get('/accounts/{account}', [AccountApiController::class, 'show'])->name('api.accounts.show');

    // Bank Accounts
    Route::get('/bank-accounts', [BankAccountApiController::class, 'index'])->name('api.bank-accounts.index');
    Route::get('/bank-accounts/{bankAccount}', [BankAccountApiController::class, 'show'])->name('api.bank-accounts.show');
    Route::post('/bank-accounts', [BankAccountApiController::class, 'store'])->name('api.bank-accounts.store');
    Route::post('/bank-accounts/{bankAccount}/imports/camt053', [BankImportApiController::class, 'store'])
        ->name('api.bank-accounts.import-camt053');

    // Webhooks
    Route::apiResource('webhooks', WebhookApiController::class)->names([
        'index' => 'api.webhooks.index',
        'show' => 'api.webhooks.show',
        'store' => 'api.webhooks.store',
        'update' => 'api.webhooks.update',
        'destroy' => 'api.webhooks.destroy',
    ]);
    Route::post('/webhooks/{webhook}/regenerate-secret', [WebhookApiController::class, 'regenerateSecret'])
        ->name('api.webhooks.regenerate-secret');
});
