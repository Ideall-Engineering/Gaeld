<?php

namespace App\Domains\Automation\Handlers;

use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceMailerService;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Models\Organization;
use App\Support\FeatureFlag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Sends payment reminders to customers.
 *
 * This automation exists to be *off*. Reminders used to go out unconditionally
 * every night from a scheduled job, which is gone; reminders are now sent by
 * hand from the invoice, or by this switch, which defaults to off,
 * because a reminder is a letter to a customer and the wrong one cannot be
 * recalled. OverdueInvoicesHandler reports the same invoices without writing to
 * anybody, and that is what runs in the meantime.
 */
class PaymentRemindersHandler implements AutomationHandler
{
    /** Minimum days between two automated reminders for the same invoice. */
    private const COOLDOWN_DAYS = 7;

    public function __construct(private readonly InvoiceMailerService $mailer) {}

    public function key(): string
    {
        return 'payment_reminders';
    }

    public function label(): string
    {
        return 'automation_payment_reminders';
    }

    public function description(): string
    {
        return 'automation_payment_reminders_desc';
    }

    public function writes(): bool
    {
        return true;
    }

    public function permission(): Permission
    {
        return Permission::InvoicingEdit;
    }

    public function run(AutomationContext $context): AutomationResult
    {
        $due = self::dueForReminder($context->organization);

        $sent = 0;
        $failures = [];

        foreach ($due as $invoice) {
            try {
                $this->mailer->sendReminder($invoice);
                $sent++;
            } catch (\DomainException|\RuntimeException|\InvalidArgumentException $e) {
                Log::error('PaymentReminders: failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);

                $failures[] = __('app.automation_reminder_failed', ['number' => (string) $invoice->number]);
            }
        }

        return AutomationResult::withFindings(
            __('app.automation_reminders_sent', ['count' => $sent]),
            $failures,
            ['sent' => $sent, 'failed' => count($failures)],
        );
    }

    /**
     * Overdue invoices that are ready for another reminder.
     *
     * @return Collection<int, Invoice>
     */
    public static function dueForReminder(Organization $organization)
    {
        return Invoice::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->overdue()
            ->whereNull('archived_at')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('email'))
            ->where(function ($q) {
                $q->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', now()->subDays(self::COOLDOWN_DAYS));
            })
            ->get();
    }

    public static function isAutomationActive(): bool
    {
        return FeatureFlag::enabled('automation');
    }
}
