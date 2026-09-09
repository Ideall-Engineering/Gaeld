<?php

namespace App\Domains\Automation\Notifications;

use App\Domains\Automation\Models\AutomationRun;
use App\Domains\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells the owners that an automation could not finish.
 *
 * Silence would be the dangerous outcome here: an automation that quietly stops
 * working looks exactly like one that has nothing to do.
 */
class AutomationFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly AutomationRun $run,
    ) {}

    /** @return string[] */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => 'automation_failed',
            'automation' => $this->run->automation,
            'automation_label' => __('app.automation_'.$this->run->automation),
            'event_key' => $this->run->event_key,
            'attempts' => $this->run->attempts,
            'message' => $this->run->message,
            'url' => route('automation.index'),
        ];
    }
}
