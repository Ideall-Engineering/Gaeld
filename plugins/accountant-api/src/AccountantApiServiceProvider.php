<?php

namespace Plugins\AccountantApi;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Api\Contracts\AbilityCatalog;
use App\Domains\Organizations\Enums\Permission;
use Illuminate\Support\ServiceProvider;

class AccountantApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'accountant-api');
        $this->loadMigrationsFrom(__DIR__.'/../migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->registerAbilities();
    }

    /**
     * Register-time ability contributions instead of editing the core
     * TokenPermissionMap directly (plan.md "Erlaubte Änderungen ausserhalb
     * des Moduls"). `correct` is a core JournalEntry ability (Track A, see
     * JournalEntryPolicy::correct()) that only becomes grantable to an API
     * token once this module — which owns the correction endpoints that
     * ability guards — is installed and active.
     */
    private function registerAbilities(): void
    {
        AbilityCatalog::register(JournalEntry::class, 'correct', Permission::AccountingEdit);
    }
}
