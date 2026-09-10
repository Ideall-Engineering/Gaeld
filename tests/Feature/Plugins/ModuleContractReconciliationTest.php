<?php

namespace Tests\Feature\Plugins;

use Tests\TestCase;

/**
 * plan.md Etappe 0: the module's own contract document must reconcile with
 * contract/api-contract.json into one collision-free overall contract.
 */
class ModuleContractReconciliationTest extends TestCase
{
    public function test_no_method_and_path_pair_is_declared_in_both_contracts(): void
    {
        $core = json_decode(file_get_contents(base_path('contract/api-contract.json')), true, flags: JSON_THROW_ON_ERROR);
        $module = json_decode(file_get_contents(base_path('plugins/accountant-api/contract.json')), true, flags: JSON_THROW_ON_ERROR);

        $corePairs = $this->methodPathPairs($core['routes'] ?? []);
        $modulePairs = $this->methodPathPairs($module['routes'] ?? []);

        $collisions = array_intersect($corePairs, $modulePairs);

        $this->assertSame([], array_values($collisions), 'Colliding (method, path) pairs: '.implode(', ', $collisions));
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, string>
     */
    private function methodPathPairs(array $routes): array
    {
        return array_map(
            fn (array $route) => strtoupper((string) $route['method']).' '.$route['path'],
            $routes,
        );
    }
}
