<?php

namespace App\Domains\Organizations\Controllers;

use App\Domains\Organizations\Enums\Role;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationInvitation;
use App\Domains\Organizations\Services\InvitationService;
use App\Domains\Users\DTOs\CreateUserData;
use App\Domains\Users\Models\User;
use App\Domains\Users\Services\UserService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invite users to an organization and accept/revoke invitations.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitationService,
    ) {}

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manageUsers', $organization);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', new Enum(Role::class)],
        ]);

        $role = Role::from($validated['role']);

        // Only owners can invite as owner
        if ($role === Role::Owner) {
            $currentUserRole = $organization->users()
                ->where('users.id', $request->user()->id)
                ->first()?->pivot?->role;

            if ($currentUserRole !== 'owner') {
                abort(403, __('app.only_owners_can_assign_owner'));
            }
        }

        $this->invitationService->invite(
            $organization,
            $validated['email'],
            $role,
            $request->user(),
        );

        return redirect()->route('organizations.show', $organization)
            ->with('success', __('app.invitation_sent'));
    }

    /**
     * Land an invited person on the one screen that can help them.
     *
     * Reachable without a session on purpose: whoever follows the link may
     * have no account at all, and the token from the e-mail is the only
     * credential they hold. Depending on who they are this accepts outright,
     * asks them to sign in, or sends them off to pick a password.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitationService->findByToken($token);
        $user = $request->user();

        if ($invitation === null || $invitation->isExpired()) {
            $message = $invitation === null
                ? __('app.invitation_invalid')
                : __('app.invitation_expired');

            return $user
                ? redirect()->route('dashboard')->with('error', $message)
                : redirect()->route('login')->with('error', $message);
        }

        if ($user === null) {
            // No account behind the invited address yet — a login screen is a
            // dead end for them, so offer to set a password instead.
            if (! User::where('email', $invitation->email)->exists()) {
                return redirect()->route('invitations.register', $token);
            }

            $request->session()->put('url.intended', route('invitations.accept', $token));

            return redirect()->route('login')
                ->with('info', __('app.invitation_sign_in_to_accept', ['email' => $invitation->email]));
        }

        abort_if(
            $user->email !== $invitation->email,
            403,
            __('app.invitation_wrong_account'),
        );

        $organization = $this->invitationService->accept($token);

        $user->switchOrganization($organization);

        return redirect()->route('dashboard')
            ->with('success', __('app.invitation_accepted', ['name' => $organization->name]));
    }

    /**
     * Password form for an invited address that has no account yet.
     *
     * Deliberately not gated on the self_registration flag: an installation
     * that closes public sign-up still wants the people it invited by hand to
     * get in.
     */
    public function createRegistration(Request $request, string $token): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('invitations.accept', $token);
        }

        $invitation = $this->invitationService->findByToken($token);

        if ($invitation === null || $invitation->isExpired()) {
            return redirect()->route('login')->with('error', $invitation === null
                ? __('app.invitation_invalid')
                : __('app.invitation_expired'));
        }

        // An account appeared since the mail went out — sign in instead.
        if (User::where('email', $invitation->email)->exists()) {
            return redirect()->route('invitations.accept', $token);
        }

        // Speak the language the invitation e-mail was written in — unless the
        // visitor has already picked one from the language switcher.
        if ($invitation->organization->locale && ! $request->session()->has('guest_locale')) {
            App::setLocale($invitation->organization->locale);
        }

        return Inertia::render('Auth/InvitationRegister', [
            'token' => $token,
            'email' => $invitation->email,
            'organizationName' => $invitation->organization->name,
        ]);
    }

    /**
     * Create the account for an invited address and join the organization.
     *
     * The address is taken from the invitation, never from the request, and
     * counts as verified: the token only ever reached that mailbox.
     */
    public function storeRegistration(Request $request, string $token, UserService $userService): RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('invitations.accept', $token);
        }

        $invitation = $this->invitationService->findByToken($token);

        if ($invitation === null || $invitation->isExpired()) {
            return redirect()->route('login')->with('error', $invitation === null
                ? __('app.invitation_invalid')
                : __('app.invitation_expired'));
        }

        if (User::where('email', $invitation->email)->exists()) {
            return redirect()->route('invitations.accept', $token);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // The account and the membership stand or fall together. Creating the
        // one without the other used to leave an account that belongs to no
        // organization, holding a password its owner chose and an invitation
        // already marked as spent.
        [$user, $organization] = DB::transaction(function () use ($validated, $invitation, $token, $userService) {
            $user = $userService->create(new CreateUserData(
                name: $validated['name'],
                email: $invitation->email,
                password: $validated['password'],
                locale: $invitation->organization->locale ?: app()->getLocale(),
                emailVerifiedAt: now(),
            ));

            // They join an organization somebody else already set up, so the
            // owner-facing setup wizard has nothing left to ask them.
            $user->forceFill(['onboarding_completed_at' => now()])->save();

            // accept() skips its own-account check while nobody is signed in,
            // which is the case here — the address comes off the token and the
            // account for it was just created two lines up.
            return [$user, $this->invitationService->accept($token)];
        });

        // Outside the transaction: a listener must not see an account that a
        // rollback is about to take away, and the session is not the
        // database's business.
        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        // From here on the account's own language applies, including the
        // welcome message flashed below.
        App::setLocale($user->locale);

        $user->switchOrganization($organization);

        return redirect()->route('dashboard')
            ->with('success', __('app.invitation_accepted', ['name' => $organization->name]));
    }

    public function destroy(Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->authorize('manageUsers', $organization);

        // Ensure invitation belongs to this organization
        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        $this->invitationService->cancel($invitation);

        return redirect()->route('organizations.show', $organization)
            ->with('success', __('app.invitation_cancelled'));
    }

    public function resend(Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->authorize('manageUsers', $organization);

        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        $this->invitationService->resend($invitation);

        return redirect()->route('organizations.show', $organization)
            ->with('success', __('app.invitation_resent'));
    }
}
