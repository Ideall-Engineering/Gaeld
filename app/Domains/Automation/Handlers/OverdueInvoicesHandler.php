<?php

namespace App\Domains\Automation\Handlers;

use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Enums\Permission;

/**
 * Reports overdue invoices. Sends nothing.
 *
 * The sending half lives in PaymentRemindersHandler, deliberately separate and
 * deliberately off: knowing who is late and writing to them are different
 * decisions, and only the first one is safe to automate on day one.
 */
class OverdueInvoicesHandler implements AutomationHandler
{
    public function key(): string
    {
        return 'overdue_invoice_report';
    }

    public function label(): string
    {
        return 'automation_overdue_invoice_report';
    }

    public function description(): string
    {
        return 'automation_overdue_invoice_report_desc';
    }

    public function writes(): bool
    {
        return false;
    }

    public function permission(): Permission
    {
        return Permission::InvoicingView;
    }

    public function run(AutomationContext $context): AutomationResult
    {
        $overdue = Invoice::where('organization_id', $context->organization->id)
            ->overdue()
            ->whereNull('archived_at')
            ->orderBy('due_date')
            ->get();

        $findings = $overdue->take(50)->map(fn (Invoice $i): string => __('app.automation_overdue_invoice', [
            'number' => (string) $i->number,
            'due' => $i->due_date->toDateString(),
            'days' => (string) $i->due_date->diffInDays(now()),
            'amount' => (string) $i->total,
        ]))->all();

        return AutomationResult::withFindings(
            __('app.automation_overdue_found', ['count' => $overdue->count()]),
            $findings,
            ['overdue' => $overdue->count()],
        );
    }
}
