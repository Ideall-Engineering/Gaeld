<?php

namespace Tests\Feature\Console;

use App\Exceptions\ConfigCacheRefusedException;
use App\Providers\AppServiceProvider;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * A config cache in a development tree is a data-loss bug, not an
 * optimisation: Laravel stops reading .env once bootstrap/cache/config.php
 * exists, so the suite never sees .env.testing, runs against the development
 * database and drops its tables. It has happened once already.
 *
 * These tests dispatch CommandStarting directly rather than calling
 * `$this->artisan('config:cache')`. If the guard ever regressed, actually
 * running the command would write bootstrap/cache/config.php and break every
 * later test in the run — the test for the safety net must not be the thing
 * that springs the trap.
 *
 * @see AppServiceProvider::refuseConfigCacheInDevelopment()
 */
class ConfigCacheGuardTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function cacheWritingCommands(): array
    {
        return [
            'config:cache' => ['config:cache'],
            'route:cache' => ['route:cache'],
            'optimize' => ['optimize'],
        ];
    }

    #[DataProvider('cacheWritingCommands')]
    public function test_it_refuses_cache_writing_commands_in_a_development_environment(string $command): void
    {
        $this->expectException(ConfigCacheRefusedException::class);

        $this->startCommand($command);
    }

    public function test_it_names_the_command_and_environment_it_refused(): void
    {
        $this->expectException(ConfigCacheRefusedException::class);
        $this->expectExceptionMessage("Refusing to run `config:cache` in the 'testing' environment.");

        $this->startCommand('config:cache');
    }

    public function test_it_explains_the_way_out_before_throwing(): void
    {
        $output = new BufferedOutput;

        try {
            $this->startCommand('config:cache', $output);
            $this->fail('The guard did not refuse the command.');
        } catch (ConfigCacheRefusedException) {
            // Expected — the point of this test is what was printed first.
        }

        $this->assertStringContainsString('config:clear', $output->fetch());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function harmlessCommands(): array
    {
        return [
            'config:clear' => ['config:clear'],
            'view:cache' => ['view:cache'],
            'event:cache' => ['event:cache'],
            'migrate' => ['migrate'],
        ];
    }

    /**
     * Clearing is the documented way out of the trap, so it must not be caught
     * by the guard that created the need for it. view:cache and event:cache do
     * not bake .env into a file and are left alone deliberately.
     */
    #[DataProvider('harmlessCommands')]
    public function test_it_leaves_other_commands_alone(string $command): void
    {
        $this->startCommand($command);

        $this->addToAssertionCount(1);
    }

    public function test_the_escape_hatch_lets_the_command_through(): void
    {
        putenv('GAELD_ALLOW_CONFIG_CACHE=1');

        try {
            $this->startCommand('config:cache');

            $this->addToAssertionCount(1);
        } finally {
            putenv('GAELD_ALLOW_CONFIG_CACHE');
        }
    }

    /**
     * Fire the event the guard listens on, without running the command behind
     * it.
     */
    private function startCommand(string $command, ?BufferedOutput $output = null): void
    {
        Event::dispatch(new CommandStarting(
            $command,
            new ArrayInput([]),
            $output ?? new BufferedOutput,
        ));
    }
}
