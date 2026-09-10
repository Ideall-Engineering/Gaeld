<?php

namespace App\Domains\Api\Contracts;

use App\Domains\Organizations\Enums\Permission;
use App\Http\Middleware\Api\TokenPermissionMap;

/**
 * Registrable extension point for API token abilities.
 *
 * {@see TokenPermissionMap} is a closed, hardcoded
 * map; a plugin cannot add a new grantable ability (e.g. for a new module
 * endpoint, or a new ability on an existing core model) without editing that
 * core file directly. Registering here instead — typically from a plugin's
 * `ServiceProvider::boot()` — keeps that file untouched.
 *
 * This is in-memory, populated fresh on every request during provider boot;
 * there is nothing to persist or invalidate.
 */
final class AbilityCatalog
{
    /**
     * @var array<class-string, array<string, Permission>>
     */
    private static array $registered = [];

    /** @param class-string $modelClass */
    public static function register(string $modelClass, string $ability, Permission $permission): void
    {
        self::$registered[$modelClass][$ability] = $permission;
    }

    /**
     * @return array<class-string, array<string, Permission>>
     */
    public static function registered(): array
    {
        return self::$registered;
    }

    /**
     * Merge the registered abilities into a base map (from
     * {@see TokenPermissionMap::get()}), registered
     * abilities for a model add to — never replace — its core ones.
     *
     * @param  array<class-string, array<string, Permission>>  $base
     * @return array<class-string, array<string, Permission>>
     */
    public static function mergeInto(array $base): array
    {
        foreach (self::$registered as $modelClass => $abilities) {
            $base[$modelClass] = [...($base[$modelClass] ?? []), ...$abilities];
        }

        return $base;
    }

    /**
     * Test-only: reset between test cases so registrations from one test
     * (or one plugin boot) cannot leak into another.
     */
    public static function flush(): void
    {
        self::$registered = [];
    }
}
