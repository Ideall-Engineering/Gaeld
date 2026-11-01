<?php

namespace App\Domains\Projects\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectsModule;

/**
 * A job follows its invoices: while one of them is open it is "invoice
 * sent", once all are paid it is done, and without any it falls back to
 * "in progress". Only a change of an invoice moves the job, never the
 * nightly step from sent to overdue, so a job closed by hand stays closed
 * until one of its invoices is sent, paid or reopened. Lost jobs and
 * credit notes are left alone.
 */
class InvoiceJobStatusService
{
    private const LIVE = [InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Paid];

    private const OPEN = [InvoiceStatus::Sent, InvoiceStatus::Overdue];

    public function statusChanged(Invoice $invoice, ?InvoiceStatus $before): void
    {
        $now = $invoice->status;
        // Sent and overdue are the same for the job.
        if (in_array($before, self::OPEN, true) && in_array($now, self::OPEN, true)) {
            return;
        }
        if (! in_array($before, self::LIVE, true) && ! in_array($now, self::LIVE, true)) {
            return;
        }

        $jobId = InvoiceProject::withoutGlobalScopes()->where('invoice_id', $invoice->id)->value('cost_center_id');
        if ($jobId !== null) {
            $this->follow($invoice, (int) $jobId);
        }
    }

    /**
     * Set the job's status from its invoices, for a job the invoice was
     * just put on or taken off. A draft moving between jobs changes nothing.
     */
    public function follow(Invoice $invoice, int $jobId): void
    {
        if ($invoice->type !== InvoiceType::Invoice || ! ProjectsModule::enabledFor($invoice->organization)) {
            return;
        }

        $job = Project::withoutGlobalScopes()
            ->where('organization_id', $invoice->organization_id)
            ->whereKey($jobId)
            ->first();
        if ($job === null || $job->kind !== ProjectKind::Project || $job->status === ProjectStatus::Lost) {
            return;
        }

        $invoiceIds = InvoiceProject::withoutGlobalScopes()->where('cost_center_id', $job->id)->pluck('invoice_id');
        $statuses = Invoice::withoutGlobalScopes()
            ->whereIn('id', $invoiceIds)
            ->where('type', InvoiceType::Invoice->value)
            ->whereIn('status', array_map(fn (InvoiceStatus $s) => $s->value, self::LIVE))
            ->pluck('status');

        $status = match (true) {
            $statuses->isEmpty() => $job->status->isBilled() ? ProjectStatus::InProgress : null,
            $statuses->contains(fn (InvoiceStatus $s) => in_array($s, self::OPEN, true)) => ProjectStatus::Invoiced,
            default => ProjectStatus::Done,
        };

        if ($status !== null && $status !== $job->status) {
            $job->update(['status' => $status]);
        }
    }
}
