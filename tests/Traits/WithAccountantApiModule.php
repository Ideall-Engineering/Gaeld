<?php

namespace Tests\Traits;

use App\Domains\Api\Contracts\AbilityCatalog;
use App\Domains\Api\Contracts\WebhookEventCatalog;
use App\Providers\PluginServiceProvider;

/**
 * Force-registers the accountant-api module for a single test's
 * Application instance. Route/provider registration happens once at real
 * application boot (with whichever PLUGINS_ENABLED the environment has —
 * false by default in phpunit.xml), so simply setting config in setUp() is
 * not enough to make the module's routes dispatchable; this constructs and
 * registers a fresh PluginServiceProvider instance the same way
 * tests/Feature/Plugins/AccountantApiModuleBootTest.php does.
 */
trait WithAccountantApiModule
{
    protected function enableAccountantApiModule(): void
    {
        config([
            'plugins.enabled' => true,
            'plugins.path' => base_path('plugins'),
            'plugins.allowed_slugs' => [],
        ]);

        $this->app->register(new PluginServiceProvider($this->app), true);
    }

    protected function tearDownAccountantApiModule(): void
    {
        AbilityCatalog::flush();
        WebhookEventCatalog::flush();
    }
}
