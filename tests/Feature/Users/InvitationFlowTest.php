<?php

namespace Tests\Feature\Users;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationInvitation;
use App\Domains\Organizations\Notifications\InvitationNotification;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithActiveSubscription;
use Tests\Traits\WithOrganizationPermissions;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase, WithActiveSubscription, WithOrganizationPermissions;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();

        $this->owner = User::factory()->create();

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'currency' => 'CHF',
        ]);

        $this->organization->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->assignOrganizationRole($this->owner, $this->organization, 'owner');

        $this->ensureSubscriptionIfSaas($this->organization);
    }

    public function test_invitation_creates_record_and_sends_notification(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)
            ->withSession(['current_organization_id' => $this->organization->id])
            ->post("/organizations/{$this->organization->id}/invitations", [
                'email' => 'invited@example.com',
                'role' => 'member',
            ]);

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $this->organization->id,
            'email' => 'invited@example.com',
            'role' => 'member',
            'invited_by' => $this->owner->id,
        ]);

        Notification::assertSentOnDemand(InvitationNotification::class);
    }

    public function test_invitation_notification_uses_organization_locale(): void
    {
        Notification::fake();

        $this->organization->update(['locale' => 'fr']);

        $this->actingAs($this->owner)
            ->withSession(['current_organization_id' => $this->organization->id])
            ->post("/organizations/{$this->organization->id}/invitations", [
                'email' => 'localized@example.com',
                'role' => 'member',
            ]);

        Notification::assertSentOnDemand(
            InvitationNotification::class,
            function (InvitationNotification $notification, array $channels, object $notifiable) {
                return $notification->locale === 'fr';
            },
        );
    }

    public function test_accept_invitation_for_existing_user(): void
    {
        $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

        $plainToken = Str::random(64);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $this->organization->id,
            'email' => 'invited@example.com',
            'role' => 'member',
            'token' => hash('sha256', $plainToken),
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($invitedUser)
            ->get("/invitations/{$plainToken}/accept");

        $response->assertRedirect('/dashboard');

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $this->organization->id,
            'user_id' => $invitedUser->id,
            'role' => 'member',
        ]);

        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_unauthenticated_accept_redirects_to_login_when_the_account_exists(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $plainToken = $this->createInvitation('existing@example.com');

        $response = $this->get("/invitations/{$plainToken}/accept");

        $response->assertRedirect('/login');
        $this->assertSame(
            route('invitations.accept', $plainToken),
            session('url.intended'),
        );
    }

    public function test_invitation_without_an_account_leads_to_the_registration_form(): void
    {
        $plainToken = $this->createInvitation('new@example.com');

        $this->get("/invitations/{$plainToken}/accept")
            ->assertRedirect("/invitations/{$plainToken}/register");

        $this->get("/invitations/{$plainToken}/register")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/InvitationRegister')
                ->where('email', 'new@example.com')
                ->where('organizationName', 'Test Org'));
    }

    public function test_registration_form_is_reachable_with_self_registration_disabled(): void
    {
        config(['features.self_registration' => false]);

        $plainToken = $this->createInvitation('closed@example.com');

        $this->get("/invitations/{$plainToken}/register")->assertOk();
    }

    public function test_invited_user_registers_and_joins_the_organization(): void
    {
        Notification::fake();

        $plainToken = $this->createInvitation('new@example.com', 'member');

        $response = $this->post("/invitations/{$plainToken}/register", [
            'name' => 'New Member',
            'password' => 'Correct-Horse-9-Battery',
            'password_confirmation' => 'Correct-Horse-9-Battery',
        ]);

        $response->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at, 'The invite token proves the address, so no second verification mail.');
        $this->assertNotNull($user->onboarding_completed_at, 'Joining an existing org must not trigger the owner setup wizard.');

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'new@example.com',
            'organization_id' => $this->organization->id,
        ]);
        $this->assertNotNull(
            OrganizationInvitation::where('email', 'new@example.com')->first()->accepted_at,
        );

        Notification::assertNothingSent();
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $plainToken = $this->createInvitation('new@example.com');

        $this->post("/invitations/{$plainToken}/register", [
            'name' => 'New Member',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_registration_ignores_an_email_supplied_by_the_visitor(): void
    {
        $plainToken = $this->createInvitation('invited@example.com');

        $this->post("/invitations/{$plainToken}/register", [
            'name' => 'Impostor',
            'email' => 'attacker@example.com',
            'password' => 'Correct-Horse-9-Battery',
            'password_confirmation' => 'Correct-Horse-9-Battery',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'invited@example.com']);
    }

    public function test_registration_with_an_expired_invitation_creates_nothing(): void
    {
        $plainToken = $this->createInvitation('late@example.com', 'member', now()->subDay());

        $this->post("/invitations/{$plainToken}/register", [
            'name' => 'Too Late',
            'password' => 'Correct-Horse-9-Battery',
            'password_confirmation' => 'Correct-Horse-9-Battery',
        ])->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
    }

    public function test_unknown_token_does_not_leak_a_404(): void
    {
        $this->get('/invitations/'.Str::random(64).'/accept')
            ->assertRedirect('/login');

        $this->get('/invitations/'.Str::random(64).'/register')
            ->assertRedirect('/login');
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $invitedUser = User::factory()->create(['email' => 'expired@example.com']);

        $plainToken = Str::random(64);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $this->organization->id,
            'email' => 'expired@example.com',
            'role' => 'member',
            'token' => hash('sha256', $plainToken),
            'invited_by' => $this->owner->id,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($invitedUser)
            ->get("/invitations/{$plainToken}/accept");

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error', __('app.invitation_expired'));

        $this->assertDatabaseMissing('organization_users', [
            'organization_id' => $this->organization->id,
            'user_id' => $invitedUser->id,
        ]);
    }

    public function test_cancel_invitation(): void
    {
        $invitation = OrganizationInvitation::create([
            'organization_id' => $this->organization->id,
            'email' => 'cancel@example.com',
            'role' => 'member',
            'token' => Str::random(64),
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($this->owner)
            ->withSession(['current_organization_id' => $this->organization->id])
            ->delete("/organizations/{$this->organization->id}/invitations/{$invitation->id}");

        $response->assertRedirect();

        $this->assertDatabaseMissing('organization_invitations', [
            'id' => $invitation->id,
        ]);
    }

    public function test_resend_invitation(): void
    {
        Notification::fake();

        $oldPlainToken = Str::random(64);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $this->organization->id,
            'email' => 'resend@example.com',
            'role' => 'member',
            'token' => hash('sha256', $oldPlainToken),
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this->actingAs($this->owner)
            ->withSession(['current_organization_id' => $this->organization->id])
            ->post("/organizations/{$this->organization->id}/invitations/{$invitation->id}/resend");

        $response->assertRedirect();

        $freshInvitation = $invitation->fresh();
        $this->assertNotEquals(hash('sha256', $oldPlainToken), $freshInvitation->token);

        // The column must hold a hash, never the token that goes out by mail —
        // otherwise the link in the resent invitation resolves to nothing.
        $this->assertSame(64, mb_strlen($freshInvitation->token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $freshInvitation->token);

        Notification::assertSentOnDemand(
            InvitationNotification::class,
            function (InvitationNotification $notification) use ($freshInvitation) {
                $url = $notification->toMail(new AnonymousNotifiable)->actionUrl;
                preg_match('#/invitations/([^/]+)/accept#', $url, $matches);

                return isset($matches[1]) && hash('sha256', $matches[1]) === $freshInvitation->token;
            },
        );
    }

    /**
     * Create a pending invitation and return its plain-text token.
     */
    private function createInvitation(string $email, string $role = 'member', ?\DateTimeInterface $expiresAt = null): string
    {
        $plainToken = Str::random(64);

        OrganizationInvitation::create([
            'organization_id' => $this->organization->id,
            'email' => $email,
            'role' => $role,
            'token' => hash('sha256', $plainToken),
            'invited_by' => $this->owner->id,
            'expires_at' => $expiresAt ?? now()->addDays(7),
        ]);

        return $plainToken;
    }
}
