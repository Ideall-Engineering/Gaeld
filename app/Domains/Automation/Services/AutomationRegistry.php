<?php

namespace App\Domains\Automation\Services;

use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\Models\AutomationSetting;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Collection;

/**
 * The list of automations this installation knows about, and whether each one
 * is allowed to run for a given organization.
 *
 * Handlers register themselves once, in AppServiceProvider. Keeping the roster
 * in one place is what lets the settings screen, the scheduler and the run log
 * agree on what exists without any of them hard-coding a list.
 */
class AutomationRegistry
{
    /** @var array<string, AutomationHandler> */
    private array $handlers = [];

    public function register(AutomationHandler $handler): void
    {
        $this->handlers[$handler->key()] = $handler;
    }

    public function get(string $key): ?AutomationHandler
    {
        return $this->handlers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    /** @return Collection<string, AutomationHandler> */
    public function all(): Collection
    {
        return collect($this->handlers);
    }

    /**
     * May this automation run for this organization?
     *
     * An automation that writes must be switched on deliberately; one that only
     * reports runs unless somebody switched it off. The absence of a settings
     * row therefore means different things for the two, which is the point.
     */
    public function isEnabled(string $key, string $organizationId): bool
    {
        $handler = $this->get($key);

        if (! $handler) {
            return false;
        }

        $setting = AutomationSetting::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('automation', $key)
            ->first();

        if ($setting) {
            return $setting->is_enabled;
        }

        return ! $handler->writes();
    }

    /**
     * The roster as the settings screen needs it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeFor(Organization $organization): array
    {
        $settings = AutomationSetting::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy('automation');

        return $this->all()
            ->map(function (AutomationHandler $handler) use ($settings): array {
                $setting = $settings->get($handler->key());

                return [
                    'key' => $handler->key(),
                    'label' => __('app.'.$handler->label()),
                    'description' => __('app.'.$handler->description()),
                    'writes' => $handler->writes(),
                    'is_enabled' => $setting->is_enabled ?? ! $handler->writes(),
                    'is_default' => $setting === null,
                    'permission' => $handler->permission()->value,
                ];
            })
            ->values()
            ->all();
    }
}
