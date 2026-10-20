<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Api\Resources\InvoiceResource;
use App\Domains\Invoicing\Actions\DuplicateInvoiceAction;
use App\Domains\Invoicing\Actions\PurgeInvoiceAction;
use App\Domains\Invoicing\Actions\RefreshInvoiceCustomerSnapshotAction;
use App\Domains\Invoicing\Actions\RevertInvoiceToDraftAction;
use App\Domains\Invoicing\Actions\UpdatePaymentDateAction;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use App\Domains\Projects\Exceptions\InvalidAllocationException;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\InvoiceProjectInput;
use App\Http\Controllers\Controller;
use App\Support\Services\FileUploadService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The invoice actions of the web interface beyond create, finalize and pay
 * (spec 011): back to draft, duplicate, payment date, customer address,
 * job, attachment and permanent removal.
 *
 * @group Invoices
 */
class InvoiceDocumentApiController extends Controller
{
    public function __construct(
        private FileUploadService $uploads,
        private OrganizationDocumentStorageService $documentStorage,
    ) {}

    /**
     * Revert invoice to draft
     *
     * Takes a finalized invoice without payments back to a draft; its
     * booking is reversed.
     *
     * @urlParam invoice string required The invoice UUID.
     */
    public function revertToDraft(Invoice $invoice, RevertInvoiceToDraftAction $action, CurrentOrganization $currentOrg): InvoiceResource|JsonResponse
    {
        $this->ensureOrganization($invoice, $currentOrg);
        $this->authorize('revertToDraft', $invoice);

        try {
            $action->execute($invoice);
        } catch (InvalidInvoiceStateException $e) {
            return $this->apiError($e->getMessage(), 'invalid_invoice_state', 422);
        }

        return new InvoiceResource($invoice->fresh(['customer', 'lines.vatRate', 'payments']));
    }

    /**
     * Duplicate invoice
     *
     * Creates a new draft with the same customer, lines and texts, dated today.
     *
     * @urlParam invoice string required The invoice UUID.
     */
    public function duplicate(Invoice $invoice, DuplicateInvoiceAction $action, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->ensureOrganization($invoice, $currentOrg);
        $this->authorize('duplicate', $invoice);

        $copy = $action->execute($invoice->loadMissing('lines'));

        return (new InvoiceResource($copy->fresh(['customer', 'lines.vatRate'])))->response()->setStatusCode(201);
    }

    /**
     * Change payment date
     *
     * @urlParam invoice string required The invoice UUID.
     * @urlParam payment string required The payment UUID.
     *
     * @bodyParam payment_date string required Example: 2026-10-20
     */
    public function updatePaymentDate(Request $request, Invoice $invoice, InvoicePayment $payment, UpdatePaymentDateAction $action, CurrentOrganization $currentOrg): InvoiceResource|JsonResponse
    {
        $this->ensureOrganization($invoice, $currentOrg);
        $this->authorize('updatePayment', $invoice);
        abort_unless((string) $payment->invoice_id === (string) $invoice->id, 404);
        $validated = $request->validate(['payment_date' => ['required', 'date']]);

        try {
            $action->execute($invoice, $payment, $validated['payment_date']);
        } catch (DomainException $e) {
            return $this->apiError($e->getMessage(), 'invalid_payment', 422);
        }

        return new InvoiceResource($invoice->fresh(['customer', 'lines.vatRate', 'payments']));
    }

    /**
     * Refresh customer address
     *
     * Takes the customer's current address and contact person into the
     * invoice, as long as it is not archived.
     *
     * @urlParam invoice string required The invoice UUID.
     */
    public function refreshCustomerSnapshot(Invoice $invoice, RefreshInvoiceCustomerSnapshotAction $action, CurrentOrganization $currentOrg): InvoiceResource|JsonResponse
    {
        $this->ensureOrganization($invoice, $currentOrg);
        $this->authorize('refreshCustomerSnapshot', $invoice);

        try {
            $action->execute($invoice);
        } catch (InvalidInvoiceStateException $e) {
            return $this->apiError($e->getMessage(), 'invalid_invoice_state', 422);
        } catch (ModelNotFoundException) {
            return $this->apiError(__('app.invoice_customer_snapshot_contact_missing'), 'customer_missing', 422);
        } catch (ValidationException $e) {
            return $this->apiError(collect($e->errors())->flatten()->implode(' '), 'invalid_customer', 422);
        }

        return new InvoiceResource($invoice->fresh(['customer', 'lines.vatRate']));
    }

    /**
     * Set invoice job
     *
     * Assigns the invoice and its revenue lines to a job, also after the
     * invoice is finalized. `project_id` null removes the job.
     *
     * @urlParam invoice string required The invoice UUID.
     *
     * @bodyParam project_id integer The job id, or null. Example: 12
     */
    public function project(Request $request, Invoice $invoice, CurrentOrganization $currentOrg): InvoiceResource|JsonResponse
    {
        $this->ensureOrganization($invoice, $currentOrg);
        $this->authorize('view', $invoice);
        $this->authorize('allocate', Project::class);
        $request->validate(['project_id' => ['present', 'nullable', 'integer']]);

        $projectId = InvoiceProjectInput::fromRequest($request, $invoice);
        if ($projectId === false) {
            return $this->apiError("Feature 'projects' is not enabled.", 'projects_unavailable', 403);
        }

        try {
            InvoiceProjectInput::apply($invoice, $projectId, $request->user()?->id);
        } catch (InvalidAllocationException $e) {
            return $this->apiError($e->getMessage(), 'invalid_allocation', 422);
        }

        return new InvoiceResource($invoice->fresh(['customer', 'lines.vatRate']));
    }

    /**
     * Attach receipt
     *
     * Stores a file (PDF or image) as the invoice's receipt, replacing an
     * earlier one. Send it as multipart form data in `file`.
     *
     * @urlParam invoice string required The invoice UUID.
     */
    public function storeJustificatif(Request $request, Invoice $invoice, CurrentOrganization $currentOrg): InvoiceResource
    {
        $this->ensureOrganization($invoice, $currentOrg);
        $this->authorize('update', $invoice);
        $request->validate([
            'file' => ['required', 'file', 'mimes:'.config('uploads.allowed_mimes.document'), 'max:'.config('uploads.max_size.document')],
        ]);

        $organization = $invoice->organization;
        $file = $request->file('file');
        $bytes = (int) $file->getSize();
        $this->documentStorage->reserve($organization, $bytes);

        try {
            $path = $this->uploads->store($file, "justificatifs/{$invoice->organization_id}");
        } catch (Throwable $e) {
            $this->documentStorage->release($organization, $bytes);

            throw $e;
        }

        $old = $invoice->justificatif_path;
        $invoice->update(['justificatif_path' => $path]);
        if ($old) {
            $this->documentStorage->delete($organization, $old);
        }

        return new InvoiceResource($invoice->fresh(['customer', 'lines.vatRate']));
    }

    /**
     * Download receipt
     *
     * @urlParam invoice string required The invoice UUID.
     */
    public function showJustificatif(Invoice $invoice, CurrentOrganization $currentOrg): StreamedResponse
    {
        $this->ensureOrganization($invoice, $currentOrg);
        $this->authorize('view', $invoice);
        abort_unless($invoice->justificatif_path && Storage::disk('local')->exists($invoice->justificatif_path), 404);

        return Storage::disk('local')->download($invoice->justificatif_path, basename($invoice->justificatif_path));
    }

    /**
     * Remove receipt
     *
     * @urlParam invoice string required The invoice UUID.
     */
    public function destroyJustificatif(Invoice $invoice, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->ensureOrganization($invoice, $currentOrg);
        $this->authorize('update', $invoice);

        if ($invoice->justificatif_path) {
            $this->documentStorage->delete($invoice->organization, $invoice->justificatif_path);
            $invoice->update(['justificatif_path' => null]);
        }

        return response()->json(null, 204);
    }

    /**
     * Remove invoice permanently
     *
     * Only a cancelled or already deleted invoice can be removed for good.
     *
     * @urlParam invoice string required The invoice UUID.
     */
    public function purge(string $invoice, PurgeInvoiceAction $action, CurrentOrganization $currentOrg): JsonResponse
    {
        $model = Invoice::withTrashed()->where('organization_id', $currentOrg->id())->findOrFail($invoice);
        $this->authorize('forceDelete', $model);

        try {
            $action->execute($model);
        } catch (InvalidInvoiceStateException $e) {
            return $this->apiError($e->getMessage(), 'invalid_invoice_state', 422);
        }

        return response()->json(null, 204);
    }

    private function ensureOrganization(Invoice $invoice, CurrentOrganization $currentOrg): void
    {
        abort_unless($invoice->organization_id === $currentOrg->id(), 404);
    }

    private function apiError(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
