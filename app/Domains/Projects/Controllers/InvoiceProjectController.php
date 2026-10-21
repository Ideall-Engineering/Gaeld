<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Projects\Exceptions\InvalidAllocationException;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\InvoiceProjectService;
use App\Domains\Projects\Support\InvoiceProjectInput;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Change the project of an invoice at any time, also once it is booked or
 * archived (spec 009, stage 2). The invoice and its entry stay untouched;
 * only the allocation of its revenue lines follows.
 */
class InvoiceProjectController extends Controller
{
    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('view', $invoice);
        $this->authorize('allocate', Project::class);

        $projectId = InvoiceProjectInput::fromRequest($request, $invoice);

        try {
            InvoiceProjectInput::apply($invoice, $projectId, $request->user()?->id);
        } catch (InvalidAllocationException $e) {
            return back()->withErrors(['project_id' => $e->getMessage()]);
        }

        return back()->with('success', __('app.invoice_project_saved'));
    }

    /**
     * A job of its own for a booked invoice that has none yet.
     */
    public function createJob(Request $request, Invoice $invoice, InvoiceProjectService $invoiceProjects): RedirectResponse
    {
        $this->authorize('view', $invoice);
        $this->authorize('allocate', Project::class);
        $this->authorize('create', Project::class);

        if ($invoiceProjects->projectIdFor($invoice) !== null || $invoice->type !== InvoiceType::Invoice) {
            return back();
        }

        $job = $invoiceProjects->attachNewJob($invoice, $request->user()?->id);

        return back()->with('success', __('app.invoice_job_created', ['code' => $job->code]));
    }
}
