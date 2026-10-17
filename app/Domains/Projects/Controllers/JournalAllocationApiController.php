<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Api\Resources\JournalEntryResource;
use App\Domains\Api\Services\ApiIdempotencyService;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\LineAllocationService;
use App\Domains\Projects\Support\AllocationRules;
use App\Domains\Projects\Support\ApiAllocations;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Replace the allocations of a journal entry over the API, whatever state
 * the entry is in (spec 009, User Story 6). The entry itself and its
 * revision stay as they are, so no If-Match is needed.
 */
class JournalAllocationApiController extends Controller
{
    /**
     * Replace journal entry allocations
     *
     * Lines not named keep their allocations; an empty list clears a line.
     * Requires an `Idempotency-Key`.
     *
     * @urlParam journalEntry string required The journal entry UUID. Example: 9c8f1b2a-3d4e-5f67-8901-abcdef123456
     */
    public function update(
        Request $request,
        JournalEntry $journalEntry,
        CurrentOrganization $currentOrganization,
        LineAllocationService $allocations,
        ApiIdempotencyService $idempotency,
    ): JsonResponse {
        abort_unless($journalEntry->organization_id === $currentOrganization->id(), 404);
        $this->authorize('view', $journalEntry);
        $this->authorize('allocate', Project::class);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            ...ApiAllocations::rules($currentOrganization->id(), 'lines.*.allocations', AllocationRules::projectsOf($journalEntry)),
            'lines.*.allocations' => ['present', 'array', 'max:20'],
        ]);

        $reservation = null;

        try {
            $reservation = $idempotency->reserve($request, $currentOrganization->id(), null);

            if ($reservation === null) {
                return response()->json([
                    'message' => 'An Idempotency-Key is required for mutations.',
                    'code' => 'idempotency_key_required',
                ], 422);
            }

            if ($reservation->replay) {
                return $idempotency->replay($reservation);
            }

            $byLine = [];
            foreach ($validated['lines'] as $line) {
                $byLine[(int) $line['line_id']] = ApiAllocations::toData($line['allocations']);
            }

            $allocations->replaceForEntry($journalEntry, $byLine, AllocationSource::Api, $request->user()?->id);

            $response = (new JournalEntryResource($journalEntry->fresh(['lines.account', 'lines.allocations.project'])))
                ->response()
                ->setStatusCode(200);

            $idempotency->complete($reservation, $response);

            return $response;
        } catch (\DomainException $exception) {
            if ($reservation !== null && ! $reservation->replay) {
                $idempotency->release($reservation);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'invalid_allocation',
            ], 422);
        }
    }
}
