<?php

namespace Tests\Feature\Plugins;

use App\Domains\Api\Support\RouteCollisionGuard;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteCollisionGuardTest extends TestCase
{
    public function test_a_snapshot_taken_now_matches_the_current_real_route_table_with_no_diff(): void
    {
        $guard = new RouteCollisionGuard;
        $snapshot = $guard->snapshot(app('router'));

        $this->assertSame([], $guard->diffAgainst($snapshot, app('router')));
    }

    public function test_a_route_overwritten_at_the_same_method_and_uri_after_the_snapshot_is_detected(): void
    {
        $guard = new RouteCollisionGuard;

        // Distinct string action references, not closures: Laravel's
        // getActionName() returns the literal string "Closure" for every
        // closure-based route, so two different closures would look
        // identical to the diff. The controller classes below need not
        // exist — action strings are only resolved at dispatch time.
        Route::get('/__test-collision-guard/foo', 'Tests\Fakes\OriginalController@handle')->name('test.collision.original');
        $before = $guard->snapshot(app('router'));

        // Simulates a plugin route silently replacing this one — Laravel's
        // RouteCollection stores routes keyed by method+URI, so the second
        // registration simply replaces the first; there is never a moment
        // with "two routes at this URI" to find by scanning the table.
        Route::get('/__test-collision-guard/foo', 'Tests\Fakes\OverwritingController@handle')->name('test.collision.overwritten');

        $collisions = $guard->diffAgainst($before, app('router'));

        $this->assertNotEmpty(array_filter(
            $collisions,
            fn (string $c) => str_contains($c, 'GET __test-collision-guard/foo'),
        ));
    }

    public function test_a_route_name_repointed_to_a_different_action_after_the_snapshot_is_detected(): void
    {
        $guard = new RouteCollisionGuard;

        Route::get('/__test-collision-guard/bar', 'Tests\Fakes\OriginalController@handle')->name('test.collision.shared-name');
        $before = $guard->snapshot(app('router'));

        Route::get('/__test-collision-guard/baz', 'Tests\Fakes\OverwritingController@handle')->name('test.collision.shared-name');

        $collisions = $guard->diffAgainst($before, app('router'));

        $this->assertNotEmpty(array_filter(
            $collisions,
            fn (string $c) => str_contains($c, "'name:test.collision.shared-name'"),
        ));
    }

    public function test_assert_no_collisions_against_throws_when_a_collision_exists(): void
    {
        $guard = new RouteCollisionGuard;

        Route::get('/__test-collision-guard/throws', 'Tests\Fakes\OriginalController@handle')->name('test.collision.throws');
        $before = $guard->snapshot(app('router'));
        Route::get('/__test-collision-guard/throws', 'Tests\Fakes\OverwritingController@handle')->name('test.collision.throws-2');

        $this->expectException(\RuntimeException::class);

        $guard->assertNoCollisionsAgainst($before, app('router'));
    }

    public function test_a_brand_new_route_added_after_the_snapshot_is_not_a_collision(): void
    {
        $guard = new RouteCollisionGuard;
        $before = $guard->snapshot(app('router'));

        Route::get('/__test-collision-guard/new-route', fn () => 'new')->name('test.collision.new-route');

        $this->assertSame([], $guard->diffAgainst($before, app('router')));
    }
}
