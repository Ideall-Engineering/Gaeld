<?php

namespace App\Providers;

use App\Console\Commands\BackfillOrganizationDocumentStorageCommand;
use App\Domains\Accounting\Events\JournalEntryCorrected;
use App\Domains\Accounting\Jobs\ExportChartOfAccountsJob;
use App\Domains\Accounting\Listeners\DispatchJournalEntryCorrectedWebhook;
use App\Domains\Accounting\Listeners\JournalEventSubscriber;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\ConsolidationGroup;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TaxDeclaration;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Policies\ConsolidationGroupPolicy;
use App\Domains\Accounting\Policies\CostCenterPolicy;
use App\Domains\Accounting\Policies\ExchangeRatePolicy;
use App\Domains\Accounting\Policies\FiscalYearPolicy;
use App\Domains\Accounting\Policies\TaxDeclarationPolicy;
use App\Domains\Api\Jobs\DispatchWebhookJob;
use App\Domains\Api\Models\PersonalAccessToken;
use App\Domains\Assets\Jobs\MonthlyDepreciationJob;
use App\Domains\Automation\Handlers\BankImportSuggestionsHandler;
use App\Domains\Automation\Handlers\MissingDocumentsHandler;
use App\Domains\Automation\Handlers\OverdueInvoicesHandler;
use App\Domains\Automation\Handlers\PaymentRemindersHandler;
use App\Domains\Automation\Handlers\QrPaymentMatchingHandler;
use App\Domains\Automation\Handlers\RecurringInvoiceDraftsHandler;
use App\Domains\Automation\Handlers\TaxDeclarationReadinessHandler;
use App\Domains\Automation\Handlers\UnclearPaymentsHandler;
use App\Domains\Automation\Models\AutomationRun;
use App\Domains\Automation\Models\AutomationSetting;
use App\Domains\Automation\Policies\AutomationPolicy;
use App\Domains\Automation\Policies\AutomationSettingPolicy;
use App\Domains\Automation\Services\AutomationRegistry;
use App\Domains\Banking\Contracts\PaymentInitiationProviderInterface;
use App\Domains\Banking\Events\BankStatementImported;
use App\Domains\Banking\Listeners\QueueBankRuleSuggestions;
use App\Domains\Banking\Models\BankRule;
use App\Domains\Banking\Models\BankRuleApplication;
use App\Domains\Banking\Policies\BankRuleApplicationPolicy;
use App\Domains\Banking\Policies\BankRulePolicy;
use App\Domains\Banking\Services\Payments\FilePain001Provider;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Policies\ContactPolicy;
use App\Domains\Contacts\Search\ContactSearchProvider;
use App\Domains\Expenses\Contracts\ReceiptOcrInterface;
use App\Domains\Expenses\Jobs\ProcessReceiptOcrJob;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Models\ExpenseCategory;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Domains\Expenses\Policies\RecurringExpensePolicy;
use App\Domains\Expenses\Search\ExpenseSearchProvider;
use App\Domains\Expenses\Services\NullOcrService;
use App\Domains\Expenses\Services\TesseractOcrService;
use App\Domains\Invoicing\Jobs\GenerateRecurringInvoicesJob;
use App\Domains\Invoicing\Jobs\SendPaymentRemindersJob;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Search\InvoiceSearchProvider;
use App\Domains\Migration\Jobs\ProcessMigrationImport;
use App\Domains\Organizations\Events\MemberRemoved;
use App\Domains\Organizations\Jobs\ExportOrganizationDataJob;
use App\Domains\Organizations\Listeners\RevokeOrganizationTokens;
use App\Domains\Organizations\Models\FiscalYearChangeRequest;
use App\Domains\Organizations\Policies\FiscalYearChangeRequestPolicy;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Contracts\SourceTaxServiceInterface;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\NullSourceTaxService;
use App\Domains\Reporting\Jobs\GenerateReportsJob;
use App\Domains\Users\Jobs\ExportUserDataJob;
use App\Domains\Users\Models\User;
use App\Exceptions\ConfigCacheRefusedException;
use App\Http\Services\GlobalSearchService;
use App\Listeners\SendHorizonTelegramAlert;
use App\Support\Contracts\EditionCompatibility;
use App\Support\Contracts\OrganizationQuotaResolver;
use App\Support\EditionCompatibility as EditionCompatibilityService;
use App\Support\EditionReleasePair;
use App\Support\Listeners\AuthAuditSubscriber;
use App\Support\Observers\LocksArchivedRecord;
use App\Support\Services\DefaultOrganizationQuotaResolver;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Scribe;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Passkeys\Passkeys;
use Laravel\Sanctum\Sanctum;

/**
 * Core application service provider — registers bindings, gates, policies, and global search providers.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([BackfillOrganizationDocumentStorageCommand::class]);
        $this->app->scoped(CurrentOrganization::class);
        $this->app->singleton(EditionCompatibility::class, EditionCompatibilityService::class);
        $this->app->singleton(
            EditionReleasePair::class,
            fn ($app): EditionReleasePair => new EditionReleasePair($app->make(EditionCompatibility::class)),
        );
        $this->app->singleton(OrganizationQuotaResolver::class, DefaultOrganizationQuotaResolver::class);
        $this->app->singleton(SourceTaxServiceInterface::class, NullSourceTaxService::class);
        $this->app->singleton(
            ReceiptOcrInterface::class,
            config('services.ocr.driver', 'tesseract') === 'tesseract'
                ? TesseractOcrService::class
                : NullOcrService::class,
        );

        // Outbound payment provider — overridden by EE GaeldEEServiceProvider
        // when the bank_sync feature is enabled and the BankAccount uses bLink.
        $this->app->bind(PaymentInitiationProviderInterface::class, FilePain001Provider::class);

        $this->registerAutomations();

        $this->app->singleton(GlobalSearchService::class, function ($app) {
            return new GlobalSearchService(
                $app->make(InvoiceSearchProvider::class),
                $app->make(ContactSearchProvider::class),
                $app->make(ExpenseSearchProvider::class),
            );
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        if (class_exists(Scribe::class)) {
            Scribe::normalizeEndpointUrlUsing(
                static fn (string $uri, mixed ...$context): string => $uri,
            );
            Scribe::afterExtracting(
                static function (ExtractedEndpointData $endpointData): void {
                    if (! str_starts_with($endpointData->uri, 'api/v1/customers')) {
                        return;
                    }

                    $endpointData->metadata->groupName = 'Customers';
                    $endpointData->metadata->title = str_replace('contact', 'customer', $endpointData->metadata->title ?? '');
                    $endpointData->metadata->description = str_replace('contact', 'customer', $endpointData->metadata->description ?? '');
                },
            );
        }

        // Authenticated users who visit /login or /register are sent to / (home)
        // instead of directly to /dashboard, matching test expectations.
        RedirectIfAuthenticated::redirectUsing(fn () => route('home'));

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Passkeys::useUserModel(User::class);

        // ── Queue Routing (Laravel 13) ──────────────────────────
        // Centralizes job→queue mapping so individual jobs don't
        // need $queue properties. Run separate Horizon supervisors
        // per queue for priority / concurrency control.
        Queue::route([
            DispatchWebhookJob::class => [null, 'webhooks'],
            ProcessReceiptOcrJob::class => [null, 'ocr'],
            ProcessMigrationImport::class => [null, 'processing'],
            ExportChartOfAccountsJob::class => [null, 'exports'],
            ExportUserDataJob::class => [null, 'exports'],
            ExportOrganizationDataJob::class => [null, 'exports'],
            GenerateRecurringInvoicesJob::class => [null, 'scheduled'],
            SendPaymentRemindersJob::class => [null, 'scheduled'],
            GenerateReportsJob::class => [null, 'scheduled'],
            MonthlyDepreciationJob::class => [null, 'scheduled'],
        ]);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('sanctum.rate_limit', 60))->by($request->user()?->id ?: $request->ip());
        });
        Password::defaults(fn () => Password::min(12)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised());

        Event::subscribe(AuthAuditSubscriber::class);
        Event::subscribe(JournalEventSubscriber::class);
        Event::listen(JournalEntryCorrected::class, DispatchJournalEntryCorrectedWebhook::class);
        Event::listen(MemberRemoved::class, RevokeOrganizationTokens::class);
        Event::listen(BankStatementImported::class, QueueBankRuleSuggestions::class);
        Event::listen(LongWaitDetected::class, SendHorizonTelegramAlert::class);
        Event::listen(CommandStarting::class, $this->refuseConfigCacheInDevelopment(...));

        Gate::policy(BankRule::class, BankRulePolicy::class);
        Gate::policy(BankRuleApplication::class, BankRuleApplicationPolicy::class);
        Gate::policy(AutomationRun::class, AutomationPolicy::class);
        Gate::policy(AutomationSetting::class, AutomationSettingPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(FiscalYearChangeRequest::class, FiscalYearChangeRequestPolicy::class);
        Gate::policy(FiscalYear::class, FiscalYearPolicy::class);
        Gate::policy(TaxDeclaration::class, TaxDeclarationPolicy::class);
        Gate::policy(CostCenter::class, CostCenterPolicy::class);
        Gate::policy(ExchangeRate::class, ExchangeRatePolicy::class);
        Gate::policy(ConsolidationGroup::class, ConsolidationGroupPolicy::class);
        Gate::policy(RecurringExpense::class, RecurringExpensePolicy::class);

        // Lock legally archived records (Swiss CO 10-year immutability).
        $registerLock = function (string $modelClass, string $documentType): void {
            $observer = new LocksArchivedRecord($documentType);
            Event::listen("eloquent.updating: {$modelClass}", fn ($model) => $observer->updating($model));
            Event::listen("eloquent.deleting: {$modelClass}", fn ($model) => $observer->deleting($model));
        };
        $registerLock(JournalEntry::class, 'journal_entry');
        $registerLock(Invoice::class, 'invoice');
        $registerLock(Expense::class, 'expense');
        $registerLock(SalarySlip::class, 'salary_slip');

        // Cache invalidation: flush tagged caches when models change
        $flushTags = function (string ...$tags) {
            return function (Model $model) use ($tags) {
                $orgId = $model->organization_id ?? null;
                if (! $orgId) {
                    return;
                }
                foreach ($tags as $tag) {
                    try {
                        Cache::tags(["org:{$orgId}:{$tag}"])->flush();
                    } catch (\BadMethodCallException) {
                        // File/array caches used in testing do not support tags.
                    }
                }
            };
        };

        $referenceFlush = $flushTags('reference');
        $contactsFlush = $flushTags('contacts');
        $dashboardFlush = $flushTags('dashboard');

        foreach (['created', 'updated', 'deleted'] as $event) {
            VatRate::$event($referenceFlush);
            Account::$event($referenceFlush);
            ExpenseCategory::$event($referenceFlush);
            Contact::$event($contactsFlush);
            Invoice::$event($dashboardFlush);
            Expense::$event($dashboardFlush);
        }
    }

    /**
     * Refuse to write a config cache in a development tree.
     *
     * Once bootstrap/cache/config.php exists, Laravel stops loading .env at
     * all. A test run then never sees .env.testing: DB_DATABASE stays on the
     * development database and RefreshDatabase drops its tables. The same
     * cache makes runningUnitTests() false, so CSRF applies and POST tests
     * fail with 419 instead of the expected status.
     *
     * Production caches config as usual — see GaeldReleaseCommand and
     * docker/production/entrypoint.sh. Set GAELD_ALLOW_CONFIG_CACHE=1 to
     * override this locally.
     */
    private function refuseConfigCacheInDevelopment(CommandStarting $event): void
    {
        $blocked = ['config:cache', 'route:cache', 'optimize'];

        if (! in_array($event->command, $blocked, true)) {
            return;
        }

        if (! $this->app->environment('local', 'testing')) {
            return;
        }

        // Read the process environment, not config(): the guard has to behave
        // the same whether or not a config cache is already in place, which is
        // exactly the situation it exists for.
        if (getenv('GAELD_ALLOW_CONFIG_CACHE') === '1') {
            return;
        }

        $exception = ConfigCacheRefusedException::forCommand(
            (string) $event->command,
            $this->app->environment(),
        );

        $exception->explainTo($event->output);

        throw $exception;
    }

    /**
     * The automations this installation knows about.
     *
     * One list, consulted by the settings screen, the scheduler and the run log
     * alike — so none of them can disagree about what exists.
     */
    private function registerAutomations(): void
    {
        $this->app->singleton(AutomationRegistry::class, function ($app): AutomationRegistry {
            $registry = new AutomationRegistry;

            foreach ([
                BankImportSuggestionsHandler::class,
                QrPaymentMatchingHandler::class,
                UnclearPaymentsHandler::class,
                RecurringInvoiceDraftsHandler::class,
                OverdueInvoicesHandler::class,
                PaymentRemindersHandler::class,
                MissingDocumentsHandler::class,
                TaxDeclarationReadinessHandler::class,
            ] as $handler) {
                $registry->register($app->make($handler));
            }

            return $registry;
        });
    }
}
