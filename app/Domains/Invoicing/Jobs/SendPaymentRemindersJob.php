<?php

namespace App\Domains\Invoicing\Jobs;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceMailerService;
use App\Support\FeatureFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPaymentRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Minimum days between automated reminders */
    private const COOLDOWN_DAYS = 7;

    public function handle(InvoiceMailerService $mailerService): void
    {
        // With the automation module on, sending reminders is a per-organization
        // decision recorded in automation_settings — and one that defaults to
        // off, because a reminder is a letter to a customer. This unconditional
        // nightly send stands down so that decision is the only one in force.
        if (FeatureFlag::enabled('automation')) {
            return;
        }

        $overdueInvoices = Invoice::withoutGlobalScope('organization')
            ->overdue()
            ->whereNull('archived_at')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('email'))
            ->where(function ($q) {
                $q->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', now()->subDays(self::COOLDOWN_DAYS));
            })
            ->get();

        foreach ($overdueInvoices as $invoice) {
            try {
                $mailerService->sendReminder($invoice);

                Log::info('SendPaymentRemindersJob: reminder sent', [
                    'invoice_id' => $invoice->id,
                    'reminder_count' => $invoice->reminder_count,
                ]);
            } catch (\DomainException|\RuntimeException|\InvalidArgumentException $e) {
                Log::error('SendPaymentRemindersJob: failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
