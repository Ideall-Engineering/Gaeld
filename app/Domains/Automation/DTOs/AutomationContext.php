<?php

namespace App\Domains\Automation\DTOs;

use App\Domains\Organizations\Models\Organization;

/**
 * Everything a handler is allowed to know about why it is running.
 *
 * `eventKey` is passed through so a handler can address the exact occasion —
 * the import it belongs to, the day it covers — rather than re-deriving it and
 * risking a mismatch with the idempotency key it was claimed under.
 */
readonly class AutomationContext
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Organization $organization,
        public string $eventKey,
        public array $payload = [],
        public bool $manual = false,
    ) {}

    public function payload(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }
}
