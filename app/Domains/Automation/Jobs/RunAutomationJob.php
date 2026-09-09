<?php

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Services\AutomationRunner;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs one automation on the queue, with the retry policy around it.
 *
 * The runner is idempotent on (organization, automation, event key), so a retry
 * resumes the same occasion instead of starting a second one. Failing loudly is
 * intentional: the queue needs the exception to schedule the next attempt, and
 * the owners have already been notified by then.
 */
class RunAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $automation,
        public readonly string $organizationId,
        public readonly string $eventKey,
        public readonly array $payload = [],
    ) {}

    /**
     * Keeps duplicate dispatches of the same occasion out of the queue entirely.
     */
    public function uniqueId(): string
    {
        return $this->automation.':'.$this->organizationId.':'.$this->eventKey;
    }

    public function handle(AutomationRunner $runner): void
    {
        $organization = Organization::find($this->organizationId);

        if (! $organization) {
            Log::warning('RunAutomationJob: organization vanished', [
                'organization_id' => $this->organizationId,
                'automation' => $this->automation,
            ]);

            return;
        }

        $runner->run($this->automation, $organization, $this->eventKey, $this->payload);
    }
}
