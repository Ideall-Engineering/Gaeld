<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Exceptions\InvalidAllocationException;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Requests\ProjectRequest;
use App\Domains\Projects\Support\ProjectsModule;
use Illuminate\Support\Facades\DB;

/**
 * The project of an invoice and what follows from it (spec 009, stage 2):
 * the revenue lines of the invoice's journal entry are allocated to it.
 *
 * The invoice record itself is never written; the link lives in
 * invoice_projects so that it stays changeable after archiving.
 */
class InvoiceProjectService
{
    public function __construct(
        private LineAllocationService $allocations,
    ) {}

    /**
     * The project an invoice belongs to; a credit note without its own
     * falls back to the project of the invoice it credits.
     */
    public function projectIdFor(Invoice $invoice): ?int
    {
        $own = InvoiceProject::withoutGlobalScopes()->where('invoice_id', $invoice->id)->value('cost_center_id');

        if ($own !== null || $invoice->related_invoice_id === null) {
            return $own === null ? null : (int) $own;
        }

        $related = InvoiceProject::withoutGlobalScopes()->where('invoice_id', $invoice->related_invoice_id)->value('cost_center_id');

        return $related === null ? null : (int) $related;
    }

    /**
     * Set or clear the project of an invoice. A booked invoice has its
     * revenue lines re-allocated at once; the entry stays untouched.
     *
     * @throws InvalidAllocationException When the project is not one of the organization's active projects
     */
    public function assign(Invoice $invoice, ?int $projectId, ?int $userId = null): void
    {
        if (! ProjectsModule::enabledFor($invoice->organization)) {
            return;
        }

        $current = InvoiceProject::withoutGlobalScopes()->where('invoice_id', $invoice->id)->first();

        if ($projectId !== null && $current?->cost_center_id !== $projectId) {
            $valid = Project::withoutGlobalScopes()
                ->where('organization_id', $invoice->organization_id)
                ->whereKey($projectId)
                ->where('is_active', true)
                ->exists();

            if (! $valid) {
                throw new InvalidAllocationException(__('app.allocation_unknown_project'));
            }
        }

        // A booked invoice always has a job; clearing it keeps the one Gäld
        // made for it rather than making yet another.
        if ($projectId === null && $current !== null && $invoice->journal_entry_id !== null && $this->ownJob($invoice) !== null) {
            return;
        }

        DB::transaction(function () use ($invoice, $projectId, $current, $userId): void {
            // The job Gäld made for this invoice goes when the invoice moves
            // to another one; it would only wait for money that is not coming.
            $own = $projectId !== null && $current !== null && $current->cost_center_id !== $projectId ? $this->ownJob($invoice) : null;

            if ($projectId === null) {
                $current?->delete();
            } elseif ($current === null) {
                InvoiceProject::create([
                    'organization_id' => $invoice->organization_id,
                    'invoice_id' => $invoice->id,
                    'cost_center_id' => $projectId,
                ]);
            } elseif ($current->cost_center_id !== $projectId) {
                $current->update(['cost_center_id' => $projectId]);
            }

            // Only a live booking follows the project. A cancelled or reverted
            // invoice has a reversal carrying the old project; moving the
            // original alone would leave the two out of balance.
            $live = in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Paid, InvoiceStatus::Overdue], true);
            $entry = ! $live || $invoice->journal_entry_id === null
                ? null
                : JournalEntry::withoutGlobalScopes()->find($invoice->journal_entry_id);

            if ($entry !== null) {
                $this->allocateEntry($invoice, $entry, $userId);
            }

            if ($own !== null && ! LineAllocation::withoutGlobalScopes()->where('cost_center_id', $own->id)->exists()) {
                $own->delete();
            }
        });
    }

    /**
     * Allocate the revenue lines of an invoice's journal entry to the
     * invoice's project, or clear them when it has none. Debtor and VAT
     * lines are left as they are.
     */
    public function allocateEntry(Invoice $invoice, JournalEntry $entry, ?int $userId = null): void
    {
        if (! ProjectsModule::enabledFor($invoice->organization)) {
            return;
        }

        $projectId = $this->projectIdFor($invoice);

        // Every booked invoice belongs to a job: one without gets its own,
        // the project above it still to be chosen.
        if ($projectId === null && $invoice->type === InvoiceType::Invoice) {
            $projectId = $this->createJobFor($invoice)->id;
        }

        // A credit note keeps the project it was booked with, even if the
        // credited invoice moves on later.
        if ($projectId !== null
            && $invoice->type === InvoiceType::CreditNote
            && ! InvoiceProject::withoutGlobalScopes()->where('invoice_id', $invoice->id)->exists()) {
            InvoiceProject::create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'cost_center_id' => $projectId,
            ]);
        }

        $revenueLines = TransactionLine::query()
            ->where('journal_entry_id', $entry->id)
            ->whereHas('account', fn ($q) => $q->where('type', AccountType::Revenue->value))
            ->pluck('id');

        $byLine = [];
        foreach ($revenueLines as $lineId) {
            $byLine[(int) $lineId] = $this->allocations->withProject((int) $lineId, $projectId, AllocationSource::Invoice);
        }

        if ($byLine !== []) {
            $this->allocations->replaceForEntry($entry, $byLine, AllocationSource::Invoice, $userId);
        }
    }

    /**
     * A new job for an invoice that has none: done, expecting the invoice's
     * net, for its customer and without a project above it yet.
     */
    public function createJobFor(Invoice $invoice): Project
    {
        $invoice->loadMissing('customer');
        $existing = InvoiceProject::withoutGlobalScopes()->where('invoice_id', $invoice->id)->first();
        if ($existing !== null) {
            $existing->delete();
        }

        $job = Project::create([
            'organization_id' => $invoice->organization_id,
            'code' => ProjectRequest::nextCode(ProjectKind::Project->value, '', $invoice->organization_id, $invoice->issue_date->year),
            'name' => $this->jobNameFor($invoice),
            'is_active' => true,
            'kind' => ProjectKind::Project,
            'status' => ProjectStatus::Done,
            'contact_id' => $invoice->customer_id,
            'expected_amount' => $invoice->subtotal,
        ]);

        InvoiceProject::create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            'cost_center_id' => $job->id,
        ]);

        return $job;
    }

    /**
     * Make a job for a booked invoice without one and allocate its revenue
     * to it, for invoices booked before every invoice got its job.
     */
    public function attachNewJob(Invoice $invoice, ?int $userId = null): Project
    {
        return DB::transaction(function () use ($invoice, $userId): Project {
            $job = $this->createJobFor($invoice);
            $live = in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Paid, InvoiceStatus::Overdue], true);
            $entry = ! $live || $invoice->journal_entry_id === null
                ? null
                : JournalEntry::withoutGlobalScopes()->find($invoice->journal_entry_id);
            if ($entry !== null) {
                $this->allocateEntry($invoice, $entry, $userId);
            }

            return $job;
        });
    }

    /**
     * A cancelled invoice takes the job made for it along: it is lost. A job
     * that was there before, or carries more than this invoice, stays.
     */
    public function invoiceCancelled(Invoice $invoice): void
    {
        $job = $this->ownJob($invoice);
        if ($job !== null) {
            $job->update(['status' => ProjectStatus::Lost]);
        }
    }

    /**
     * The job Gäld made for this invoice, while nothing else uses it.
     */
    private function ownJob(Invoice $invoice): ?Project
    {
        $jobId = InvoiceProject::withoutGlobalScopes()->where('invoice_id', $invoice->id)->value('cost_center_id');
        $job = $jobId === null ? null : Project::withoutGlobalScopes()->whereKey($jobId)->first();
        if ($job === null || $job->name !== $this->jobNameFor($invoice)) {
            return null;
        }

        $others = InvoiceProject::withoutGlobalScopes()->where('cost_center_id', $job->id)->where('invoice_id', '<>', $invoice->id)->exists()
            || Quote::withoutGlobalScopes()->where('cost_center_id', $job->id)->exists();

        return $others ? null : $job;
    }

    private function jobNameFor(Invoice $invoice): string
    {
        return trim(($invoice->customer->name ?? '').' ('.$invoice->number.')');
    }

    /**
     * The project to put on a new invoice for a customer: their one open
     * project, or none when there is no single obvious choice.
     */
    public function suggestionForCustomer(string $organizationId, ?int $contactId): ?int
    {
        if ($contactId === null) {
            return null;
        }

        $open = Project::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('contact_id', $contactId)
            ->where('is_active', true)
            ->where('kind', '<>', ProjectKind::Area->value)
            ->whereNotIn('status', [ProjectStatus::Done->value, ProjectStatus::Lost->value])
            ->pluck('id');

        return $open->count() === 1 ? (int) $open->first() : null;
    }
}
