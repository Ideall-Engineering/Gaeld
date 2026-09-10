<?php

namespace App\Domains\Api\Support;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Detects a plugin silently overwriting a core route (same method+URI, or
 * same route name, now pointing at a different action).
 *
 * Laravel's own RouteCollection stores routes keyed by method+URI (and
 * separately by name): registering a second route at an already-used
 * method+URI does not raise an error or keep both — it silently replaces
 * the first entry. That means a final scan of `Router::getRoutes()` alone
 * can never find "two routes with the same URI" (only one ever survives to
 * be found), so this guard instead compares a *snapshot* taken before a
 * plugin's routes load against the table after: any key that existed
 * before, now pointing at a different action, was silently overwritten —
 * exactly what plan.md forbids ("Das Modul ersetzt oder überschattet keine
 * Kernroute").
 *
 * Scope: this catches a plugin overwriting a route that existed in the
 * snapshot (core, or an earlier-loaded plugin). It does not catch two
 * plugins loaded in the same pass both introducing the *same new* route —
 * Laravel's silent-replace behavior makes that undetectable after the fact
 * for the same reason. With one plugin in this repository today that gap
 * has no real consequence; closing it would require intercepting route
 * registration itself, not just comparing before/after snapshots.
 */
final class RouteCollisionGuard
{
    /**
     * @return array<string, string> "METHOD uri" and "name:<name>" => action name
     */
    public function snapshot(Router $router): array
    {
        $snapshot = [];

        /** @var Route $route */
        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $snapshot[$method.' '.$route->uri()] = $route->getActionName();
            }

            if ($route->getName() !== null) {
                $snapshot['name:'.$route->getName()] = $route->getActionName();
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, string>  $before  From an earlier {@see snapshot()} call
     * @return array<int, string> Human-readable collision descriptions; empty when none found
     */
    public function diffAgainst(array $before, Router $router): array
    {
        $after = $this->snapshot($router);
        $collisions = [];

        foreach ($before as $key => $action) {
            if (isset($after[$key]) && $after[$key] !== $action) {
                $collisions[] = "'{$key}' was '{$action}', now '{$after[$key]}'";
            }
        }

        return $collisions;
    }

    /**
     * @param  array<string, string>  $before
     *
     * @throws \RuntimeException When any collision is found
     */
    public function assertNoCollisionsAgainst(array $before, Router $router): void
    {
        $collisions = $this->diffAgainst($before, $router);

        if ($collisions !== []) {
            throw new \RuntimeException(
                "A plugin silently overwrote existing route(s):\n- ".implode("\n- ", $collisions)
            );
        }
    }
}
