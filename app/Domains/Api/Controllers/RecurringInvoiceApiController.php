<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Api\Requests\RecurringInvoiceApiRequest;
use App\Domains\Api\Resources\RecurringInvoiceResource;
use App\Domains\Invoicing\Models\RecurringInvoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Recurring invoices: a template that creates an invoice draft on each
 * issue date, as on the web.
 *
 * @group Recurring invoices
 */
class RecurringInvoiceApiController extends Controller
{
    /**
     * List recurring invoices
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RecurringInvoice::class);

        return RecurringInvoiceResource::collection(
            RecurringInvoice::with('customer')->orderByDesc('next_issue_date')->paginate(50)
        );
    }

    /**
     * Show recurring invoice
     *
     * @urlParam recurringInvoice string required The UUID.
     */
    public function show(RecurringInvoice $recurringInvoice): RecurringInvoiceResource
    {
        $this->authorize('view', $recurringInvoice);

        return new RecurringInvoiceResource($recurringInvoice->load('customer'));
    }

    /**
     * Create recurring invoice
     */
    public function store(RecurringInvoiceApiRequest $request, CurrentOrganization $currentOrg): JsonResponse
    {
        $validated = $request->validated();
        $recurring = RecurringInvoice::create([
            'organization_id' => $currentOrg->id(),
            'customer_id' => $request->customer()?->id,
            'frequency' => $validated['frequency'],
            'next_issue_date' => $validated['next_issue_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'template_data' => $this->template($validated, [], $currentOrg->id()),
        ]);

        return (new RecurringInvoiceResource($recurring->load('customer')))->response()->setStatusCode(201);
    }

    /**
     * Update recurring invoice
     *
     * Fields left out keep their value; `lines`, when given, replace all lines.
     *
     * @urlParam recurringInvoice string required The UUID.
     */
    public function update(RecurringInvoiceApiRequest $request, RecurringInvoice $recurringInvoice, CurrentOrganization $currentOrg): RecurringInvoiceResource
    {
        $validated = $request->validated();
        $recurringInvoice->update([
            ...(array_key_exists('customer_id', $validated) ? ['customer_id' => $request->customer()?->id] : []),
            ...collect($validated)->only(['frequency', 'next_issue_date', 'end_date', 'is_active'])->all(),
            'template_data' => $this->template($validated, (array) $recurringInvoice->template_data, $currentOrg->id()),
        ]);

        return new RecurringInvoiceResource($recurringInvoice->fresh('customer'));
    }

    /**
     * Delete recurring invoice
     *
     * @urlParam recurringInvoice string required The UUID.
     */
    public function destroy(RecurringInvoice $recurringInvoice): JsonResponse
    {
        $this->authorize('delete', $recurringInvoice);
        $recurringInvoice->delete();

        return response()->json(null, 204);
    }

    /**
     * Pause recurring invoice
     *
     * @urlParam recurringInvoice string required The UUID.
     */
    public function pause(RecurringInvoice $recurringInvoice): RecurringInvoiceResource
    {
        $this->authorize('update', $recurringInvoice);
        $recurringInvoice->update(['is_active' => false]);

        return new RecurringInvoiceResource($recurringInvoice->fresh('customer'));
    }

    /**
     * Resume recurring invoice
     *
     * @urlParam recurringInvoice string required The UUID.
     */
    public function resume(RecurringInvoice $recurringInvoice): RecurringInvoiceResource
    {
        $this->authorize('update', $recurringInvoice);
        $recurringInvoice->update(['is_active' => true]);

        return new RecurringInvoiceResource($recurringInvoice->fresh('customer'));
    }

    /**
     * The template as the job reads it, VAT rates by internal id.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function template(array $validated, array $current, string $orgId): array
    {
        $template = [...$current, ...collect($validated)->only(['notes', 'payment_terms', 'currency', 'contact_person_id'])->all()];

        if (isset($validated['lines'])) {
            $template['lines'] = [];
            foreach (array_values((array) $validated['lines']) as $i => $line) {
                $template['lines'][] = [
                    'description' => $line['description'],
                    'quantity' => (string) $line['quantity'],
                    'unit' => $line['unit'] ?? null,
                    'unit_price' => (string) $line['unit_price'],
                    'vat_rate_id' => isset($line['vat_rate_id'])
                        ? VatRate::where('uuid', $line['vat_rate_id'])->where('organization_id', $orgId)->value('id')
                        : null,
                    'sort_order' => $i,
                ];
            }
        }

        return $template;
    }
}
