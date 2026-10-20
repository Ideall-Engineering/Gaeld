<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Api\Requests\QuoteApiRequest;
use App\Domains\Api\Resources\InvoiceResource;
use App\Domains\Api\Resources\QuoteResource;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\ChangeQuoteStatusAction;
use App\Domains\Invoicing\Actions\ConvertQuoteToInvoiceAction;
use App\Domains\Invoicing\Actions\DeleteQuoteAction;
use App\Domains\Invoicing\Actions\GenerateQuotePdfAction;
use App\Domains\Invoicing\Actions\SaveQuoteAction;
use App\Domains\Invoicing\DTOs\QuoteData;
use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Models\QuoteLine;
use App\Domains\Invoicing\Queries\QuoteQuery;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;

/**
 * @group Quotes
 *
 * Quotes (offers) to customers: the same lines, VAT and totals as invoices,
 * but nothing is booked. Lifecycle: `draft` → `sent` → `accepted` or
 * `declined`. An accepted quote is turned into an invoice draft with
 * `POST /quotes/{id}/convert`.
 */
class QuoteApiController extends Controller
{
    /**
     * List quotes
     *
     * @queryParam filter[status] string `draft`, `sent`, `accepted` or `declined`. Example: sent
     * @queryParam sort string `issue_date`, `valid_until`, `total`, `number`, `status`; prefix `-` for descending. Example: -issue_date
     * @queryParam search string Number, title or customer name. Example: OF-2026
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Quote::class);

        return QuoteResource::collection(QuoteQuery::list($request));
    }

    /**
     * Show quote
     *
     * @urlParam quote string required The quote UUID.
     */
    public function show(Quote $quote): QuoteResource
    {
        $this->authorize('view', $quote);

        return new QuoteResource($quote->load(['customer', 'lines.vatRate']));
    }

    /**
     * Create quote
     *
     * Creates a quote in `draft` status; the number (`OF-YYYY-NNN`) is
     * assigned by Gäld.
     *
     * @bodyParam customer_id string UUID of a contact; required before the quote is sent. Example: 9c8f1a2b-3c4d-5e6f-7a8b-9c0d1e2f3a4b
     * @bodyParam issue_date string required Date in YYYY-MM-DD format. Example: 2026-10-18
     * @bodyParam valid_until string Validity date, on or after issue_date. Example: 2026-11-17
     * @bodyParam title string Subject shown under the heading. Example: Workshop KI für Lehrpersonen
     * @bodyParam currency string ISO 4217 code. Example: CHF
     * @bodyParam tax_treatment string `standard` or `reverse_charge`. Example: standard
     * @bodyParam notes string Text below the totals.
     * @bodyParam payment_terms string Payment terms in days, used for the invoice. Example: 30
     * @bodyParam project_id integer Job (project module) the quote is for; carried to the invoice.
     * @bodyParam lines object[] required At least one line.
     * @bodyParam lines[].type string `item`, `discount` or `text`. Example: item
     * @bodyParam lines[].discount_type string For a discount: `flat` or `percentage`.
     * @bodyParam lines[].description string required Example: Workshop, halber Tag
     * @bodyParam lines[].quantity number Quantity (not for text lines). Example: 2
     * @bodyParam lines[].unit_price number Unit price, or the percentage of a percentage discount. Example: 1200
     * @bodyParam lines[].vat_rate_id string UUID of a VAT rate.
     */
    public function store(QuoteApiRequest $request, SaveQuoteAction $save, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('create', Quote::class);
        $validated = $request->validated();

        try {
            $quote = $save->create(
                QuoteData::fromArray($this->payload($validated, $currentOrg->id())),
                isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            );
        } catch (DomainException $e) {
            return $this->apiError($e->getMessage(), 'invalid_quote', 422);
        }

        return (new QuoteResource($quote->load(['customer', 'lines.vatRate'])))->response()->setStatusCode(201);
    }

    /**
     * Update quote
     *
     * Changes a draft quote. Fields left out keep their value; `lines`, when
     * given, replace all lines.
     *
     * @urlParam quote string required The quote UUID.
     */
    public function update(QuoteApiRequest $request, Quote $quote, SaveQuoteAction $save, CurrentOrganization $currentOrg): QuoteResource|JsonResponse
    {
        $this->authorize('update', $quote);
        $validated = $request->validated();
        $quote->load('lines.vatRate', 'customer');

        $merged = [
            'customer_id' => $quote->customer?->uuid,
            'issue_date' => $quote->issue_date->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'title' => $quote->title,
            'currency' => $quote->currency,
            'tax_treatment' => $quote->tax_treatment->value,
            'notes' => $quote->notes,
            'payment_terms' => $quote->payment_terms,
            'contact_person_id' => $quote->contact_person_id,
            'delivery_note' => $quote->delivery_note,
            'intro_text' => $quote->intro_text,
            'lines' => $quote->lines->map(fn (QuoteLine $line) => [
                'type' => $line->type->value,
                'discount_type' => $line->discount_type,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'vat_rate_id' => $line->vatRate?->uuid,
            ])->all(),
            ...$validated,
        ];

        try {
            $save->update(
                $quote,
                QuoteData::fromArray($this->payload($merged, $currentOrg->id())),
                array_key_exists('project_id', $validated) ? ($validated['project_id'] === null ? null : (int) $validated['project_id']) : false,
            );
        } catch (DomainException $e) {
            return $this->apiError($e->getMessage(), 'invalid_quote_state', 422);
        }

        return new QuoteResource($quote->fresh(['customer', 'lines.vatRate']));
    }

    /**
     * Delete quote
     *
     * Deletes a `draft` or `declined` quote.
     *
     * @urlParam quote string required The quote UUID.
     */
    public function destroy(Quote $quote, DeleteQuoteAction $delete): JsonResponse
    {
        $this->authorize('delete', $quote);

        try {
            $delete->execute($quote);
        } catch (DomainException $e) {
            return $this->apiError($e->getMessage(), 'invalid_quote_state', 422);
        }

        return response()->json(null, 204);
    }

    /**
     * Mark quote as sent
     *
     * @urlParam quote string required The quote UUID.
     */
    public function send(Quote $quote, ChangeQuoteStatusAction $change): QuoteResource|JsonResponse
    {
        return $this->changeTo($quote, QuoteStatus::Sent, $change);
    }

    /**
     * Mark quote as accepted
     *
     * @urlParam quote string required The quote UUID.
     */
    public function accept(Quote $quote, ChangeQuoteStatusAction $change): QuoteResource|JsonResponse
    {
        return $this->changeTo($quote, QuoteStatus::Accepted, $change);
    }

    /**
     * Mark quote as declined
     *
     * @urlParam quote string required The quote UUID.
     */
    public function decline(Quote $quote, ChangeQuoteStatusAction $change): QuoteResource|JsonResponse
    {
        return $this->changeTo($quote, QuoteStatus::Declined, $change);
    }

    /**
     * Back to draft
     *
     * Reopens a sent or declined quote for editing.
     *
     * @urlParam quote string required The quote UUID.
     */
    public function reopen(Quote $quote, ChangeQuoteStatusAction $change): QuoteResource|JsonResponse
    {
        return $this->changeTo($quote, QuoteStatus::Draft, $change);
    }

    /**
     * Convert to invoice
     *
     * Turns a sent or accepted quote into an invoice draft with the same
     * customer, lines and job, and marks the quote accepted. Returns the
     * invoice. A quote is converted once.
     *
     * @urlParam quote string required The quote UUID.
     */
    public function convert(Request $request, Quote $quote, ConvertQuoteToInvoiceAction $convert): JsonResponse
    {
        $this->authorize('convert', $quote);

        try {
            $invoice = $convert->execute($quote, $request->user()?->id);
        } catch (DomainException $e) {
            return $this->apiError($e->getMessage(), 'invalid_quote_state', 422);
        }

        return (new InvoiceResource($invoice->load(['customer', 'lines.vatRate'])))->response()->setStatusCode(201);
    }

    /**
     * Quote PDF
     *
     * The quote as a PDF in the invoice layout; a draft carries a draft mark.
     *
     * @urlParam quote string required The quote UUID.
     */
    public function pdf(Quote $quote, GenerateQuotePdfAction $pdf, CurrentOrganization $currentOrg): HttpResponse
    {
        $this->authorize('view', $quote);
        $organization = $currentOrg->get();

        return new HttpResponse($pdf->execute($quote, $organization, $organization->locale ?? 'de'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.QuoteQuery::fileName($quote).'"',
        ]);
    }

    private function changeTo(Quote $quote, QuoteStatus $target, ChangeQuoteStatusAction $change): QuoteResource|JsonResponse
    {
        $this->authorize('changeStatus', $quote);

        try {
            $change->execute($quote, $target);
        } catch (DomainException $e) {
            return $this->apiError($e->getMessage(), 'invalid_quote_state', 422);
        }

        return new QuoteResource($quote->fresh(['customer', 'lines.vatRate']));
    }

    /**
     * Contact and VAT rate UUIDs to the internal ids QuoteData expects.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data, string $orgId): array
    {
        $data['organization_id'] = $orgId;
        $data['customer_id'] = isset($data['customer_id'])
            ? Contact::where('uuid', $data['customer_id'])->where('organization_id', $orgId)->value('id')
            : null;
        $data['lines'] = array_map(function (array $line) use ($orgId) {
            $line['vat_rate_id'] = isset($line['vat_rate_id'])
                ? VatRate::where('uuid', $line['vat_rate_id'])->where('organization_id', $orgId)->value('id')
                : null;
            $line['quantity'] = (string) ($line['quantity'] ?? '0');
            $line['unit_price'] = (string) ($line['unit_price'] ?? '0');

            return $line;
        }, $data['lines']);
        unset($data['project_id']);

        return $data;
    }

    private function apiError(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
