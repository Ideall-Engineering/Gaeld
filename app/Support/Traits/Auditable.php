<?php

namespace App\Support\Traits;

use App\Domains\Api\Models\PersonalAccessToken;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Adds organisation-scoped audit logging to a model.
 *
 * Uses Spatie Activity Log under the hood, automatically recording:
 *  - created / updated / deleted events
 *  - changed attributes (old → new)
 *  - the authenticated user (causer)
 *  - the organization_id, request source, token, and correlation context
 *    via properties
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => class_basename($this)." {$event}");
    }

    public function tapActivity(Activity $activity): void
    {
        if (! $activity instanceof ActivityModel) {
            return;
        }

        $properties = [];

        if (property_exists($this, 'organization_id') && $this->organization_id) {
            $properties['organization_id'] = $this->organization_id;
        }

        $properties = [...$properties, ...$this->requestContextProperties()];

        if ($properties !== []) {
            $activity->properties = $activity->properties->merge($properties);
        }
    }

    /**
     * Actor/token/source/correlation-id/idempotency-key context for the
     * request causing this activity, when one exists (plan.md "Request-
     * Kontext für Akteur, Token, Quelle, Correlation- und Idempotency-Key im
     * Activity-Log"). Silently empty outside an HTTP request (console,
     * queued jobs) — there is nothing meaningful to attach there.
     *
     * @return array<string, mixed>
     */
    private function requestContextProperties(): array
    {
        if (! app()->bound('request')) {
            return [];
        }

        $request = request();
        $properties = [
            'source' => str_starts_with((string) ($request->route()?->getName() ?? ''), 'api.') ? 'api' : 'web',
        ];

        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $properties['token_id'] = $token->id;
            $properties['token_type'] = $token->type->value;
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey !== '') {
            $properties['idempotency_key'] = $idempotencyKey;
        }

        $correlationId = $request->attributes->get('correlation_id');
        if (! is_string($correlationId)) {
            $correlationId = trim((string) $request->header('X-Correlation-Id')) ?: (string) Str::uuid();
            $request->attributes->set('correlation_id', $correlationId);
        }
        $properties['correlation_id'] = $correlationId;

        return $properties;
    }
}
