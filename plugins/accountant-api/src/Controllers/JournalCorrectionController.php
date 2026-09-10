<?php

namespace Plugins\AccountantApi\Controllers;

use App\Domains\Api\Services\ApiIdempotencyService;
use App\Domains\Api\Support\ApiIdempotencyReservation;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\AccountantApi\CoreBridge\JournalCorrectionBridge;
use Plugins\AccountantApi\Requests\PrepareJournalCorrectionRequest;
use Plugins\AccountantApi\Requests\UpdateJournalCorrectionReplacementRequest;
use Plugins\AccountantApi\Resources\JournalCorrectionResource;

/**
 * Guided journal correction endpoints (plan.md "API-Vertrag"). Calls only
 * {@see JournalCorrectionBridge} — no core Domain-Action, model, or
 * exception class is referenced here directly (see
 * tests/Feature/Plugins/AccountantApiModuleBoundaryTest.php). Route params
 * are plain strings, not implicit model bindings, for the same reason: a
 * `JournalEntry`/`JournalCorrection` type-hint here would itself be a core
 * import.
 */
class JournalCorrectionController extends Controller
{
    public function __construct(
        private JournalCorrectionBridge $bridge,
        private CurrentOrganization $currentOrganization,
    ) {}

    public function store(
        PrepareJournalCorrectionRequest $request,
        string $original,
        ApiIdempotencyService $idempotency,
    ): JsonResponse {
        $organizationId = $this->currentOrganization->id();

        try {
            $originalEntry = $this->bridge->findOriginalOrFail($organizationId, $original);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if (! $this->bridge->userCanCorrect($request->user(), $originalEntry)) {
            abort(403);
        }

        $validated = $request->validated();

        return $this->executeMutation(
            $request,
            $organizationId,
            null, // plan.md: all correction mutations require Idempotency-Key explicitly
            $idempotency,
            function () use ($request, $originalEntry, $validated): JsonResponse {
                $correction = $this->bridge->prepare(
                    original: $originalEntry,
                    reason: $validated['reason'],
                    correctionDate: $validated['correction_date'],
                    replacementPayload: $request->hasReplacementPayload() ? $validated : null,
                    userId: $request->user()?->id,
                    tokenId: $request->user()?->currentAccessToken()?->id,
                    clientOperationId: $request->header('Idempotency-Key'),
                    requestHash: hash('sha256', $request->getContent()),
                );

                return (new JournalCorrectionResource($correction))->response()->setStatusCode(201);
            },
        );
    }

    public function show(Request $request, string $correction): JournalCorrectionResource
    {
        $organizationId = $this->currentOrganization->id();

        try {
            $correctionModel = $this->bridge->findCorrectionOrFail($organizationId, $correction);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if (! $this->bridge->userCanCorrect($request->user(), $correctionModel->original)) {
            abort(403);
        }

        return new JournalCorrectionResource($correctionModel);
    }

    public function updateReplacement(
        UpdateJournalCorrectionReplacementRequest $request,
        string $correction,
        ApiIdempotencyService $idempotency,
    ): JsonResponse {
        $organizationId = $this->currentOrganization->id();

        try {
            $correctionModel = $this->bridge->findCorrectionOrFail($organizationId, $correction);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if (! $this->bridge->userCanCorrect($request->user(), $correctionModel->original)) {
            abort(403);
        }

        $validated = $request->validated();

        return $this->executeMutation(
            $request,
            $organizationId,
            null,
            $idempotency,
            function () use ($correctionModel, $validated): JsonResponse {
                $updated = $this->bridge->updateReplacement($correctionModel, $validated);

                return (new JournalCorrectionResource($updated))->response()->setStatusCode(200);
            },
        );
    }

    public function post(Request $request, string $correction, ApiIdempotencyService $idempotency): JsonResponse
    {
        $organizationId = $this->currentOrganization->id();

        try {
            $correctionModel = $this->bridge->findCorrectionOrFail($organizationId, $correction);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if (! $this->bridge->userCanCorrect($request->user(), $correctionModel->original)) {
            abort(403);
        }

        return $this->executeMutation(
            $request,
            $organizationId,
            null,
            $idempotency,
            function () use ($correctionModel): JsonResponse {
                $posted = $this->bridge->post($correctionModel);

                return (new JournalCorrectionResource($posted))->response()->setStatusCode(200);
            },
        );
    }

    public function destroy(Request $request, string $correction, ApiIdempotencyService $idempotency): JsonResponse
    {
        $organizationId = $this->currentOrganization->id();

        try {
            $correctionModel = $this->bridge->findCorrectionOrFail($organizationId, $correction);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if (! $this->bridge->userCanCorrect($request->user(), $correctionModel->original)) {
            abort(403);
        }

        return $this->executeMutation(
            $request,
            $organizationId,
            null,
            $idempotency,
            function () use ($correctionModel): JsonResponse {
                $this->bridge->cancel($correctionModel);

                return response()->json(null, 204);
            },
        );
    }

    /**
     * @param  Closure(): JsonResponse  $operation
     */
    private function executeMutation(
        Request $request,
        string $organizationId,
        ?string $fallbackReference,
        ApiIdempotencyService $idempotency,
        Closure $operation,
    ): JsonResponse {
        $reservation = null;

        try {
            $reservation = $idempotency->reserve($request, $organizationId, $fallbackReference);

            if ($reservation === null) {
                return response()->json([
                    'message' => 'An Idempotency-Key is required for this mutation.',
                    'code' => 'idempotency_key_required',
                ], 422);
            }

            if ($reservation->replay) {
                return $idempotency->replay($reservation);
            }

            $response = $operation();
            $idempotency->complete($reservation, $response);

            return $response;
        } catch (\DomainException $exception) {
            $this->releaseReservation($reservation, $idempotency);

            return $this->domainError($exception);
        }
    }

    private function releaseReservation(?ApiIdempotencyReservation $reservation, ApiIdempotencyService $idempotency): void
    {
        if ($reservation !== null && ! $reservation->replay) {
            $idempotency->release($reservation);
        }
    }

    private function domainError(\DomainException $exception): JsonResponse
    {
        $code = $this->bridge->errorCodeFor($exception);

        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $code,
        ], $this->bridge->httpStatusFor($code));
    }
}
