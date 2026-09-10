<?php

namespace Tests\Feature\Plugins;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Api\Contracts\AbilityCatalog;
use App\Domains\Api\Contracts\WebhookEventCatalog;
use App\Providers\PluginServiceProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Boot/smoke matrix for the plugin system with the accountant-api module:
 * absent, present+compatible, present+incompatible (plan.md Etappe 0 gate).
 *
 * Each scenario constructs its own PluginServiceProvider instance with
 * config scoped to that scenario, rather than relying on the container's
 * provider — service providers are not resolvable singletons in the usual
 * sense, and the real app boots with whichever PLUGINS_ENABLED the
 * environment happens to have.
 */
class AccountantApiModuleBootTest extends TestCase
{
    protected function tearDown(): void
    {
        AbilityCatalog::flush();
        WebhookEventCatalog::flush();

        parent::tearDown();
    }

    public function test_the_core_boots_with_no_plugins_active(): void
    {
        config(['plugins.enabled' => false]);

        $provider = new PluginServiceProvider($this->app);
        $this->app->register($provider, true);

        $this->assertSame([], $provider->loadedPlugins());
    }

    public function test_the_compatible_accountant_api_module_boots_and_registers(): void
    {
        config([
            'plugins.enabled' => true,
            'plugins.path' => base_path('plugins'),
            'plugins.allowed_slugs' => [],
        ]);

        $provider = new PluginServiceProvider($this->app);
        $this->app->register($provider, true);

        $this->assertArrayHasKey('accountant-api', $provider->loadedPlugins());
        $this->assertArrayHasKey(JournalEntry::class, AbilityCatalog::registered());
        $this->assertArrayHasKey('correct', AbilityCatalog::registered()[JournalEntry::class]);
    }

    public function test_an_incompatible_module_is_skipped_not_crashed(): void
    {
        $tempDir = sys_get_temp_dir().'/gaeld-test-plugins-'.uniqid();
        File::makeDirectory($tempDir.'/bad-plugin', 0755, true);
        File::put($tempDir.'/bad-plugin/plugin.json', json_encode([
            'name' => 'Bad Plugin',
            'slug' => 'bad-plugin',
            'version' => '0.0.1',
            'provider' => 'Plugins\\BadPlugin\\BadPluginServiceProvider',
            'enabled' => true,
            'core_contract_version' => '99.0.0',
            'requires' => [],
        ]));

        try {
            config([
                'plugins.enabled' => true,
                'plugins.path' => $tempDir,
                'plugins.allowed_slugs' => [],
            ]);

            $provider = new PluginServiceProvider($this->app);
            $this->app->register($provider, true);

            $this->assertSame([], $provider->loadedPlugins());
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    public function test_the_deployment_allow_list_restricts_which_plugin_activates(): void
    {
        config([
            'plugins.enabled' => true,
            'plugins.path' => base_path('plugins'),
            'plugins.allowed_slugs' => ['example-plugin'],
        ]);

        $provider = new PluginServiceProvider($this->app);
        $this->app->register($provider, true);

        $this->assertArrayHasKey('example-plugin', $provider->loadedPlugins());
        $this->assertArrayNotHasKey('accountant-api', $provider->loadedPlugins());
    }
}
