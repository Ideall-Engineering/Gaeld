<?php

namespace Tests\Feature\Automation;

use App\Domains\Automation\Enums\AutomationStatus;
use App\Domains\Automation\Models\AutomationRun;
use App\Domains\Automation\Models\AutomationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class AutomationHttpTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Config::set('features.automation', true);
    }

    public function test_the_screen_is_reachable_when_the_module_is_on(): void
    {
        $this->actAsOrg()->get('/automation')->assertStatus(200);
    }

    public function test_the_screen_is_forbidden_when_the_module_is_off(): void
    {
        Config::set('features.automation', false);

        $this->actAsOrg()->get('/automation')->assertStatus(403);
    }

    public function test_an_automation_can_be_switched_on(): void
    {
        $this->actAsOrg()->put('/automation/settings', [
            'automation' => 'qr_payment_matching',
            'is_enabled' => true,
        ])->assertRedirect();

        $setting = AutomationSetting::firstOrFail();
        $this->assertSame('qr_payment_matching', $setting->automation);
        $this->assertTrue($setting->is_enabled);
        $this->assertSame($this->user->id, $setting->updated_by);
    }

    public function test_switching_the_same_automation_again_updates_rather_than_duplicates(): void
    {
        foreach ([true, false] as $enabled) {
            $this->actAsOrg()->put('/automation/settings', [
                'automation' => 'qr_payment_matching',
                'is_enabled' => $enabled,
            ])->assertRedirect();
        }

        $this->assertSame(1, AutomationSetting::count());
        $this->assertFalse(AutomationSetting::firstOrFail()->is_enabled);
    }

    public function test_an_unknown_automation_cannot_be_configured(): void
    {
        $this->actAsOrg()->put('/automation/settings', [
            'automation' => 'does_not_exist',
            'is_enabled' => true,
        ])->assertStatus(404);
    }

    public function test_a_run_can_be_triggered_by_hand_and_is_recorded(): void
    {
        $this->actAsOrg()->post('/automation/run', [
            'automation' => 'overdue_invoice_report',
        ])->assertRedirect();

        $run = AutomationRun::firstOrFail();
        $this->assertSame('overdue_invoice_report', $run->automation);
        $this->assertSame(AutomationStatus::Succeeded, $run->status);
        $this->assertSame($this->user->id, $run->triggered_by);
        $this->assertStringStartsWith('manual:', $run->event_key);
    }

    public function test_triggering_a_switched_off_automation_records_that_it_was_skipped(): void
    {
        $this->actAsOrg()->post('/automation/run', [
            'automation' => 'qr_payment_matching',
        ])->assertRedirect();

        $this->assertSame(AutomationStatus::Skipped, AutomationRun::firstOrFail()->status);
    }

    public function test_two_manual_runs_do_not_collide_with_each_other(): void
    {
        foreach (range(1, 2) as $_) {
            $this->actAsOrg()->post('/automation/run', [
                'automation' => 'overdue_invoice_report',
            ])->assertRedirect();
        }

        // Distinct occasions: an operator asking twice should be answered twice.
        $this->assertSame(2, AutomationRun::count());
    }
}
