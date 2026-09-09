<?php

namespace App\Domains\Automation\Handlers;

use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Jobs\GenerateRecurringInvoicesJob;
use App\Domains\Invoicing\Services\InvoiceNumberGenerator;
use App\Domains\Organizations\Enums\Permission;

/**
 * Drafts the recurring invoices that have come due.
 *
 * They are created as drafts and stay that way — nothing is finalized, nothing
 * is sent. It still counts as writing, because a draft consumes an invoice
 * number and advances the recurrence schedule, so it has to be switched on.
 */
class RecurringInvoiceDraftsHandler implements AutomationHandler
{
    public function __construct(
        private readonly GenerateRecurringInvoicesJob $generator,
        private readonly CreateInvoiceAction $createInvoice,
        private readonly InvoiceNumberGenerator $numberGenerator,
    ) {}

    public function key(): string
    {
        return 'recurring_invoice_drafts';
    }

    public function label(): string
    {
        return 'automation_recurring_invoice_drafts';
    }

    public function description(): string
    {
        return 'automation_recurring_invoice_drafts_desc';
    }

    public function writes(): bool
    {
        return true;
    }

    public function permission(): Permission
    {
        return Permission::InvoicingCreate;
    }

    public function run(AutomationContext $context): AutomationResult
    {
        $result = $this->generator->generateDue(
            $this->createInvoice,
            $this->numberGenerator,
            $context->organization->id,
        );

        $findings = $result['failed'] > 0
            ? [__('app.automation_recurring_failed', ['count' => $result['failed']])]
            : [];

        return AutomationResult::withFindings(
            __('app.automation_recurring_created', ['count' => $result['created']]),
            $findings,
            $result,
        );
    }
}
