<?php

namespace App\Domains\Api\Contracts;

use App\Domains\Api\Enums\WebhookEvent;
use App\Domains\Api\Requests\StoreWebhookRequest;

/**
 * Registrable extension point for webhook event names.
 *
 * {@see WebhookEvent} is a native PHP enum — closed by definition, so a
 * plugin cannot add its own case to it. A module-owned event (one that has
 * no reasonable home as a core enum case) registers its name and label here
 * instead; {@see StoreWebhookRequest} and the
 * `/meta/webhook-events` discovery endpoint both accept catalog entries the
 * same way they accept native cases. A core event (dispatched regardless of
 * which, if any, modules are installed) still belongs on the enum directly —
 * see `journal_entry.corrected`, added there rather than here.
 *
 * In-memory, populated fresh on every request during provider boot.
 */
final class WebhookEventCatalog
{
    /** @var array<string, string> */
    private static array $registered = [];

    public static function register(string $event, string $label): void
    {
        self::$registered[$event] = $label;
    }

    public static function isValid(string $event): bool
    {
        return WebhookEvent::isValid($event) || array_key_exists($event, self::$registered);
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_values(array_unique([...WebhookEvent::all(), ...array_keys(self::$registered)]));
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
