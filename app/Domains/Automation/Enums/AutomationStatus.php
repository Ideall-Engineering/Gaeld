<?php

namespace App\Domains\Automation\Enums;

/**
 * Outcome of a single automation run.
 *
 * Skipped is kept distinct from Succeeded so that "nothing happened because the
 * automation is switched off" never reads as "nothing needed doing".
 */
enum AutomationStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isFinished(): bool
    {
        return $this !== self::Running;
    }

    public function label(): string
    {
        return __('app.automation_status_'.$this->value);
    }
}
