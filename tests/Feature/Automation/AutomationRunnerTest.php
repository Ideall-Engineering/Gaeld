<?php

namespace Tests\Feature\Automation;

use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Automation\Enums\AutomationStatus;
use App\Domains\Automation\Models\AutomationRun;
use App\Domains\Automation\Models\AutomationSetting;
use App\Domains\Automation\Services\AutomationRegistry;
use App\Domains\Automation\Services\AutomationRunner;
use App\Domains\Organizations\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The guarantees the pilot rests on, tested against throwaway handlers so the
 * framework is exercised rather than any one workflow's business logic.
 */
class AutomationRunnerTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private AutomationRegistry $registry;

    private AutomationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Config::set('features.automation', true);

        $this->registry = new AutomationRegistry;
        $this->runner = new AutomationRunner($this->registry);
    }

    private function handler(
        string $key = 'test_report',
        bool $writes = false,
        ?\Closure $body = null,
    ): AutomationHandler {
        return new class($key, $writes, $body) implements AutomationHandler
        {
            public int $calls = 0;

            public function __construct(
                private readonly string $key,
                private readonly bool $writes,
                private readonly ?\Closure $body,
            ) {}

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'automation_'.$this->key;
            }

            public function description(): string
            {
                return 'automation_'.$this->key.'_desc';
            }

            public function writes(): bool
            {
                return $this->writes;
            }

            public function permission(): Permission
            {
                return Permission::AccountingView;
            }

            public function run(AutomationContext $context): AutomationResult
            {
                $this->calls++;

                if ($this->body) {
                    return ($this->body)($context);
                }

                return AutomationResult::of('done', ['calls' => $this->calls]);
            }
        };
    }

    // ──────────────────────────────────────────────────────────────
    //  No double processing
    // ──────────────────────────────────────────────────────────────

    public function test_the_same_occasion_is_only_ever_handled_once(): void
    {
        $handler = $this->handler();
        $this->registry->register($handler);

        $this->runner->run('test_report', $this->org, 'daily:2026-01-15');
        $second = $this->runner->run('test_report', $this->org, 'daily:2026-01-15');

        $this->assertNull($second);
        $this->assertSame(1, $handler->calls);
        $this->assertSame(1, AutomationRun::count());
    }

    public function test_a_different_occasion_runs_again(): void
    {
        $handler = $this->handler();
        $this->registry->register($handler);

        $this->runner->run('test_report', $this->org, 'daily:2026-01-15');
        $this->runner->run('test_report', $this->org, 'daily:2026-01-16');

        $this->assertSame(2, $handler->calls);
        $this->assertSame(2, AutomationRun::count());
    }

    public function test_a_failed_occasion_is_retried_on_the_same_row(): void
    {
        $attempts = 0;
        $this->registry->register($this->handler('test_report', false, function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new \RuntimeException('transient');
            }

            return AutomationResult::of('recovered');
        }));

        try {
            $this->runner->run('test_report', $this->org, 'daily:2026-01-15');
        } catch (\RuntimeException) {
            // expected — rethrown so the queue can retry
        }

        $this->assertSame(AutomationStatus::Failed, AutomationRun::firstOrFail()->status);

        $this->runner->run('test_report', $this->org, 'daily:2026-01-15');

        $run = AutomationRun::firstOrFail();
        $this->assertSame(1, AutomationRun::count());
        $this->assertSame(AutomationStatus::Succeeded, $run->status);
        $this->assertSame(2, $run->attempts);
    }

    // ──────────────────────────────────────────────────────────────
    //  Approval and the off switch
    // ──────────────────────────────────────────────────────────────

    public function test_an_automation_that_writes_does_not_run_until_it_is_switched_on(): void
    {
        $handler = $this->handler('test_writer', true);
        $this->registry->register($handler);

        $run = $this->runner->run('test_writer', $this->org, 'daily:2026-01-15');

        $this->assertSame(0, $handler->calls);
        $this->assertSame(AutomationStatus::Skipped, $run->status);
    }

    public function test_a_reporting_automation_runs_without_being_switched_on(): void
    {
        $handler = $this->handler('test_report', false);
        $this->registry->register($handler);

        $run = $this->runner->run('test_report', $this->org, 'daily:2026-01-15');

        $this->assertSame(1, $handler->calls);
        $this->assertSame(AutomationStatus::Succeeded, $run->status);
    }

    public function test_switching_a_writer_on_lets_it_run(): void
    {
        $handler = $this->handler('test_writer', true);
        $this->registry->register($handler);

        AutomationSetting::create([
            'organization_id' => $this->org->id,
            'automation' => 'test_writer',
            'is_enabled' => true,
        ]);

        $run = $this->runner->run('test_writer', $this->org, 'daily:2026-01-15');

        $this->assertSame(1, $handler->calls);
        $this->assertSame(AutomationStatus::Succeeded, $run->status);
    }

    public function test_switching_a_reporter_off_stops_it(): void
    {
        $handler = $this->handler('test_report', false);
        $this->registry->register($handler);

        AutomationSetting::create([
            'organization_id' => $this->org->id,
            'automation' => 'test_report',
            'is_enabled' => false,
        ]);

        $run = $this->runner->run('test_report', $this->org, 'daily:2026-01-15');

        $this->assertSame(0, $handler->calls);
        $this->assertSame(AutomationStatus::Skipped, $run->status);
    }

    public function test_nothing_runs_while_the_module_is_off(): void
    {
        Config::set('features.automation', false);

        $handler = $this->handler();
        $this->registry->register($handler);

        $run = $this->runner->run('test_report', $this->org, 'daily:2026-01-15');

        $this->assertSame(0, $handler->calls);
        $this->assertSame(AutomationStatus::Skipped, $run->status);
    }

    // ──────────────────────────────────────────────────────────────
    //  Traceability and failure notice
    // ──────────────────────────────────────────────────────────────

    public function test_a_run_records_its_message_and_findings(): void
    {
        $this->registry->register($this->handler('test_report', false, fn () => AutomationResult::withFindings(
            '2 Punkte offen',
            ['Beleg fehlt', 'MWST-Satz fehlt'],
            ['checked' => 7],
        )));

        $run = $this->runner->run('test_report', $this->org, 'daily:2026-01-15');

        $this->assertSame('2 Punkte offen', $run->message);
        $this->assertSame(7, $run->summary['checked']);
        $this->assertSame(['Beleg fehlt', 'MWST-Satz fehlt'], $run->findings());
    }

    public function test_a_failure_is_recorded_and_the_owners_are_told(): void
    {
        Notification::fake();

        $this->registry->register($this->handler('test_report', false, function () {
            throw new \RuntimeException('the printer is on fire');
        }));

        try {
            $this->runner->run('test_report', $this->org, 'daily:2026-01-15');
            $this->fail('The runner must rethrow so the queue can retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('the printer is on fire', $e->getMessage());
        }

        $run = AutomationRun::firstOrFail();
        $this->assertSame(AutomationStatus::Failed, $run->status);
        $this->assertSame('the printer is on fire', $run->message);

        Notification::assertSentTo(
            $this->user,
            \App\Domains\Automation\Notifications\AutomationFailedNotification::class,
        );
    }

    public function test_an_unknown_automation_is_ignored_rather_than_fatal(): void
    {
        $this->assertNull($this->runner->run('does_not_exist', $this->org, 'daily:2026-01-15'));
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_a_manual_run_records_who_asked(): void
    {
        $this->registry->register($this->handler());

        $run = $this->runner->run('test_report', $this->org, 'manual:1', [], $this->user);

        $this->assertSame($this->user->id, $run->triggered_by);
    }

    public function test_the_handler_is_told_it_was_triggered_by_hand(): void
    {
        $seen = null;
        $this->registry->register($this->handler('test_report', false, function (AutomationContext $c) use (&$seen) {
            $seen = $c->manual;

            return AutomationResult::of('done');
        }));

        $this->runner->run('test_report', $this->org, 'manual:1', [], $this->user);

        $this->assertTrue($seen);
    }
}
