<?php

namespace App\Domains\Automation\Services;

use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\Enums\AutomationStatus;
use App\Domains\Automation\Models\AutomationRun;
use App\Domains\Automation\Notifications\AutomationFailedNotification;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes one automation, once per occasion, and writes down what happened.
 *
 * Every guarantee the pilot depends on lives here rather than in the handlers:
 *
 *   Double processing   The run is claimed by inserting its (organization,
 *                       automation, event_key) row. A unique violation means
 *                       somebody already has it, and we stop.
 *   Traceability        Every attempt leaves a row, including the ones that
 *                       were skipped and why.
 *   Retries             Handled by the queue job around this, which increments
 *                       `attempts` on the claimed row rather than re-claiming.
 *   Authorization       Manual triggers are authorized by the controller; the
 *                       runner records who asked.
 *   Approval            An automation that writes runs only once enabled.
 *   Off switch          The same setting, cleared.
 *   Failure notice      Owners are notified; the error is on the run.
 */
class AutomationRunner
{
    public function __construct(
        private readonly AutomationRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function run(
        string $key,
        Organization $organization,
        string $eventKey,
        array $payload = [],
        ?User $triggeredBy = null,
    ): ?AutomationRun {
        $handler = $this->registry->get($key);

        if (! $handler) {
            Log::warning('AutomationRunner: unknown automation', ['automation' => $key]);

            return null;
        }

        if (FeatureFlag::disabled('automation')) {
            return $this->record($key, $organization, $eventKey, AutomationStatus::Skipped,
                __('app.automation_skipped_feature_off'), $triggeredBy);
        }

        if (! $this->registry->isEnabled($key, $organization->id)) {
            return $this->record($key, $organization, $eventKey, AutomationStatus::Skipped,
                __('app.automation_skipped_disabled'), $triggeredBy);
        }

        $run = $this->claim($key, $organization, $eventKey, $triggeredBy);

        if (! $run) {
            // Already handled — this occasion is somebody else's.
            return null;
        }

        try {
            $result = $handler->run(new AutomationContext(
                organization: $organization,
                eventKey: $eventKey,
                payload: $payload,
                manual: $triggeredBy !== null,
            ));

            $run->update([
                'status' => AutomationStatus::Succeeded,
                'message' => $result->message,
                'summary' => [...$result->summary, 'findings' => $result->findings],
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => AutomationStatus::Failed,
                'message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            Log::error('AutomationRunner: automation failed', [
                'automation' => $key,
                'organization_id' => $organization->id,
                'event_key' => $eventKey,
                'message' => $e->getMessage(),
            ]);

            $this->notifyOwners($organization, $run);

            // Rethrown so the queue can retry; the claimed row is reused.
            throw $e;
        }

        return $run->fresh();
    }

    /**
     * Take ownership of this occasion, or find out somebody already has.
     *
     * A failed run is retried on the same row: the occasion has not changed, so
     * claiming it a second time would defeat the unique index that protects it.
     */
    private function claim(
        string $key,
        Organization $organization,
        string $eventKey,
        ?User $triggeredBy,
    ): ?AutomationRun {
        $existing = AutomationRun::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('automation', $key)
            ->where('event_key', $eventKey)
            ->first();

        if ($existing) {
            if ($existing->status === AutomationStatus::Failed) {
                $existing->update([
                    'status' => AutomationStatus::Running,
                    'attempts' => $existing->attempts + 1,
                    'started_at' => now(),
                    'finished_at' => null,
                ]);

                return $existing;
            }

            return null;
        }

        try {
            return AutomationRun::create([
                'organization_id' => $organization->id,
                'automation' => $key,
                'event_key' => $eventKey,
                'status' => AutomationStatus::Running,
                'attempts' => 1,
                'triggered_by' => $triggeredBy?->id,
                'started_at' => now(),
            ]);
        } catch (QueryException) {
            // Two workers reached the same occasion at once; the other won.
            return null;
        }
    }

    private function record(
        string $key,
        Organization $organization,
        string $eventKey,
        AutomationStatus $status,
        string $message,
        ?User $triggeredBy,
    ): ?AutomationRun {
        $existing = AutomationRun::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('automation', $key)
            ->where('event_key', $eventKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return AutomationRun::create([
                'organization_id' => $organization->id,
                'automation' => $key,
                'event_key' => $eventKey,
                'status' => $status,
                'attempts' => 0,
                'message' => $message,
                'triggered_by' => $triggeredBy?->id,
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        } catch (QueryException) {
            return null;
        }
    }

    private function notifyOwners(Organization $organization, AutomationRun $run): void
    {
        $owners = $organization->users()->wherePivot('role', 'owner')->get();

        foreach ($owners as $owner) {
            $owner->notify(new AutomationFailedNotification($run));
        }
    }
}
