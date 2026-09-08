<?php

namespace Tests\Feature\Auth;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the self_registration feature flag, which lets an installation close
 * public sign-up while keeping the initial setup wizard and existing logins
 * working.
 */
class SelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function createOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Test Org',
            'currency' => 'CHF',
        ]);
    }

    private function disableSelfRegistration(): void
    {
        config(['features.self_registration' => false]);
    }

    public function test_registration_form_is_available_when_self_registration_is_enabled(): void
    {
        $this->createOrganization();
        config(['features.self_registration' => true]);

        $this->get('/register')->assertOk();
    }

    public function test_registration_form_is_blocked_when_self_registration_is_disabled(): void
    {
        $this->createOrganization();
        $this->disableSelfRegistration();

        $this->get('/register')->assertForbidden();
    }

    public function test_registration_post_is_blocked_server_side_when_disabled(): void
    {
        $this->createOrganization();
        $this->disableSelfRegistration();

        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
        $this->assertGuest();
    }

    public function test_existing_user_can_still_log_in_when_registration_is_disabled(): void
    {
        $this->createOrganization();
        $this->disableSelfRegistration();

        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_setup_wizard_stays_available_on_an_empty_installation(): void
    {
        $this->disableSelfRegistration();

        $this->get('/setup')->assertOk();
    }
}
