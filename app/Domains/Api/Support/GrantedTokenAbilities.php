<?php

namespace App\Domains\Api\Support;

use App\Domains\Users\Models\User;
use App\Http\Middleware\Api\TokenPermissionMap;

/**
 * The token abilities a person actually holds.
 *
 * Offering the whole catalogue was never a way past a role: on a personal
 * token the ability only narrows, because EnsureApiOrganization lets the
 * policy have the final word. It was, however, a way to build a token whose
 * first call answers with a puzzling 403 — an accountant could tick
 * organization.delete and learn nothing from the form.
 *
 * On an organization token the ability is the whole gate; the policy is
 * bypassed by design. There the restriction does real work: an admin holds
 * every permission except organization.delete, and could otherwise mint a
 * credential carrying the one thing their role withholds.
 */
final class GrantedTokenAbilities
{
    /**
     * @return list<string>
     */
    public static function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $granted = $user->getAllPermissions()->pluck('name')->all();

        return array_values(array_intersect(TokenPermissionMap::abilities(), $granted));
    }

    /**
     * The same set, plus the wildcard and the legacy spellings that map into it.
     *
     * A wildcard token means "everything I may do", which the policy still
     * decides for a personal token, so it stays available.
     *
     * @return list<string>
     */
    public static function accepted(?User $user): array
    {
        $granted = self::for($user);

        $legacy = array_values(array_filter(
            TokenPermissionMap::acceptedAbilities(),
            fn (string $ability): bool => $ability !== '*'
                && ! in_array($ability, TokenPermissionMap::abilities(), true)
                && array_intersect(TokenPermissionMap::normalize([$ability]), $granted) !== [],
        ));

        return array_values(array_unique([...$granted, ...$legacy, '*']));
    }
}
