<?php

namespace Tests\Unit\Api;

use App\Domains\Api\Exceptions\ApiIdempotencyConflictException;
use App\Domains\Api\Services\ApiIdempotencyService;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * plan.md Etappe 0 gate: "Crash/Retry erzeugt keine Duplikate" — a process
 * abort between reserving an idempotency key and completing the request
 * (HandleApiIdempotency::complete()) must never let a retry execute the
 * mutation a second time. It is safe for the retry to come back as a 409
 * conflict instead of a literal replay of the (never produced) response —
 * "no duplicates" is the invariant, not "always replays."
 */
class ApiIdempotencyProcessAbortTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retry_after_an_incomplete_reservation_conflicts_instead_of_creating_a_duplicate(): void
    {
        $service = app(ApiIdempotencyService::class);
        $organization = Organization::factory()->create();

        $request = Request::create('/api/v1/journal-entries', 'POST', ['reference' => 'JE-1']);
        $request->headers->set('Idempotency-Key', 'retry-after-crash-key');
        $request->setRouteResolver(fn () => new class
        {
            public function getName(): string
            {
                return 'api.journal-entries.store';
            }
        });

        $first = $service->reserve($request, $organization->id, null);
        $this->assertNotNull($first);
        $this->assertFalse($first->replay);

        // The process "crashes" here: no complete() and no release() is
        // ever called for $first, exactly as a hard timeout or kill would
        // leave things — the reservation row exists but is not completed.

        $this->expectException(ApiIdempotencyConflictException::class);

        $service->reserve($request, $organization->id, null);
    }

    public function test_a_retry_after_a_completed_reservation_replays_the_same_response(): void
    {
        $service = app(ApiIdempotencyService::class);
        $organization = Organization::factory()->create();

        $request = Request::create('/api/v1/journal-entries', 'POST', ['reference' => 'JE-2']);
        $request->headers->set('Idempotency-Key', 'retry-after-success-key');
        $request->setRouteResolver(fn () => new class
        {
            public function getName(): string
            {
                return 'api.journal-entries.store';
            }
        });

        $first = $service->reserve($request, $organization->id, null);
        $service->complete($first, response()->json(['data' => ['id' => 'abc']], 201));

        $second = $service->reserve($request, $organization->id, null);

        $this->assertTrue($second->replay);
        $this->assertSame(201, $second->record->response_status);
    }
}
