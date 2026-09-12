<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Api\Enums\TokenType;
use App\Domains\Api\Models\PersonalAccessToken;
use App\Domains\Api\Requests\StoreOrganizationTokenSettingsRequest;
use App\Domains\Api\Requests\StorePersonalTokenSettingsRequest;
use App\Domains\Api\Support\GrantedTokenAbilities;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\TokenPermissionMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings page for personal and organization API tokens.
 */
class TokenSettingsController extends Controller
{
    public function index(Request $request, CurrentOrganization $currentOrg): Response
    {
        $organization = $currentOrg->get();

        // Membership is the whole requirement. A personal token is the member's
        // own credential, and every API endpoint authorises against the holder's
        // own permissions — so the token can never reach further than they can.
        // Organisation-wide tokens below stay behind manageUsers.
        $this->authorize('view', $organization);

        $orgId = $currentOrg->id();
        $canManageOrgTokens = $request->user()->can('manageUsers', $organization);

        $personalTokens = $request->user()
            ->tokens()
            ->personal()
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at']);

        // Withheld rather than merely hidden in the template: somebody who
        // cannot manage these has no business receiving their names, abilities
        // and usage in the page payload.
        $orgTokens = $canManageOrgTokens
            ? PersonalAccessToken::query()
                ->organization()
                ->where('organization_id', $orgId)
                ->with('tokenable:id,name')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                    'created_at' => $token->created_at,
                    'created_by' => $token->tokenable->name,
                ])
            : collect();

        return Inertia::render('Settings/ApiTokens', [
            'personalTokens' => $personalTokens,
            'orgTokens' => $orgTokens,
            'canManageOrgTokens' => $canManageOrgTokens,
            'abilities' => GrantedTokenAbilities::for($request->user()),
            'apiBaseUrl' => url('/api/v1'),
            'docsUrl' => ApiInfoController::DOCUMENTATION_URL,
        ]);
    }

    public function storePersonal(StorePersonalTokenSettingsRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $organization = $currentOrg->get();
        $this->authorize('view', $organization);

        $validated = $request->validated();

        $abilities = TokenPermissionMap::normalize($validated['abilities'] ?? []);
        $abilities = $abilities === [] ? ['*'] : $abilities;
        $expiresAt = isset($validated['expires_in_days'])
            ? now()->addDays((int) $validated['expires_in_days'])
            : null;

        $token = $request->user()->createToken($validated['name'], $abilities, $expiresAt);
        $token->accessToken->update([
            'organization_id' => $currentOrg->id(),
            'type' => TokenType::Personal,
        ]);

        return redirect()->route('settings.api-tokens')
            ->with('success', __('app.token_created'))
            ->with('newToken', $token->plainTextToken);
    }

    public function storeOrganization(StoreOrganizationTokenSettingsRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $organization = $currentOrg->get();
        $this->authorize('manageUsers', $organization);

        $validated = $request->validated();

        $abilities = TokenPermissionMap::normalize($validated['abilities'] ?? []);
        $abilities = $abilities === [] ? ['*'] : $abilities;
        $expiresAt = isset($validated['expires_in_days'])
            ? now()->addDays((int) $validated['expires_in_days'])
            : null;

        $token = $request->user()->createToken($validated['name'], $abilities, $expiresAt);
        $token->accessToken->update([
            'organization_id' => $currentOrg->id(),
            'type' => TokenType::Organization,
        ]);

        return redirect()->route('settings.api-tokens')
            ->with('success', __('app.token_created'))
            ->with('newToken', $token->plainTextToken);
    }

    public function destroyPersonal(Request $request, int $tokenId, CurrentOrganization $currentOrg): RedirectResponse
    {
        $token = $request->user()
            ->tokens()
            ->personal()
            ->where('id', $tokenId)
            ->where('organization_id', $currentOrg->id())
            ->firstOrFail();

        $token->delete();

        return redirect()->route('settings.api-tokens')
            ->with('success', __('app.token_deleted'));
    }

    public function destroyOrganization(int $tokenId, CurrentOrganization $currentOrg): RedirectResponse
    {
        $organization = $currentOrg->get();
        $this->authorize('manageUsers', $organization);

        $token = PersonalAccessToken::query()
            ->organization()
            ->where('id', $tokenId)
            ->where('organization_id', $currentOrg->id())
            ->firstOrFail();

        $token->delete();

        return redirect()->route('settings.api-tokens')
            ->with('success', __('app.token_deleted'));
    }
}
