<?php

namespace App\Domains\Invoicing\Controllers;

use App\Domains\Accounting\Queries\VatRateQuery;
use App\Domains\Contacts\Queries\ContactQuery;
use App\Domains\Invoicing\Actions\ChangeQuoteStatusAction;
use App\Domains\Invoicing\Actions\ConvertQuoteToInvoiceAction;
use App\Domains\Invoicing\Actions\DeleteQuoteAction;
use App\Domains\Invoicing\Actions\GenerateQuotePdfAction;
use App\Domains\Invoicing\Actions\SaveQuoteAction;
use App\Domains\Invoicing\DTOs\QuoteData;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Queries\QuoteQuery;
use App\Domains\Invoicing\Requests\QuoteRequest;
use App\Domains\Invoicing\Services\QuoteNumberGenerator;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Support\InvoiceProjectInput;
use App\Http\Controllers\Concerns\HandlesFlashErrorResponses;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quotes (offers): write them, send them, record the answer, and turn an
 * accepted one into an invoice draft.
 */
class QuoteController extends Controller
{
    use HandlesFlashErrorResponses;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Quote::class);

        return Inertia::render('Quotes/Index', [
            'quotes' => QuoteQuery::list($request),
            'statuses' => QuoteStatus::options(),
            'query' => [
                'sort' => $request->input('sort', 'issue_date'),
                'direction' => $request->input('direction', 'desc'),
                'search' => $request->input('search', ''),
                'filter' => $request->input('filter', []),
            ],
            'can' => ['create' => $request->user()->can('create', Quote::class)],
        ]);
    }

    public function create(Request $request, QuoteNumberGenerator $numbers, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('create', Quote::class);

        return Inertia::render('Quotes/Form', [
            ...$this->formProps($request),
            'quote' => null,
            'suggestedNumber' => $numbers->next($currentOrg->id()),
            'defaultNotes' => '',
            'defaultValidDays' => 30,
        ]);
    }

    public function store(QuoteRequest $request, SaveQuoteAction $save): RedirectResponse
    {
        $projectId = $this->projectFrom($request);

        try {
            $quote = $save->create(QuoteData::fromArray($request->quoteData()), $projectId === false ? null : $projectId);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('quotes.show', $quote)->with('success', __('app.quote_created'));
    }

    public function show(Request $request, Quote $quote): Response
    {
        $this->authorize('view', $quote);

        $quote->load(['customer', 'lines.vatRate', 'invoice:id,number,status', 'project:id,code,name']);
        $user = $request->user();

        return Inertia::render('Quotes/Show', [
            'quote' => [...$quote->toArray(), 'is_expired' => $quote->isExpired()],
            'can' => [
                'update' => $user->can('update', $quote),
                'change_status' => $user->can('changeStatus', $quote),
                'convert' => $user->can('convert', $quote),
                'delete' => $user->can('delete', $quote),
            ],
        ]);
    }

    public function edit(Request $request, Quote $quote): Response
    {
        $this->authorize('update', $quote);

        return Inertia::render('Quotes/Form', [
            ...$this->formProps($request),
            'quote' => $quote->load('lines'),
            'suggestedNumber' => $quote->number,
            'defaultNotes' => '',
            'defaultValidDays' => 30,
        ]);
    }

    public function update(QuoteRequest $request, Quote $quote, SaveQuoteAction $save): RedirectResponse
    {
        $projectId = $this->projectFrom($request, $quote);

        try {
            $save->update($quote, QuoteData::fromArray($request->quoteData()), $projectId);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('quotes.show', $quote)->with('success', __('app.quote_updated'));
    }

    public function status(Request $request, Quote $quote, ChangeQuoteStatusAction $change): RedirectResponse
    {
        $this->authorize('changeStatus', $quote);
        $validated = $request->validate(['status' => ['required', Rule::enum(QuoteStatus::class)]]);

        try {
            $change->execute($quote, QuoteStatus::from($validated['status']));
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return back()->with('success', __('app.quote_status_changed', ['status' => $quote->status->label()]));
    }

    public function convert(Request $request, Quote $quote, ConvertQuoteToInvoiceAction $convert): RedirectResponse
    {
        $this->authorize('convert', $quote);

        try {
            $invoice = $convert->execute($quote, $request->user()?->id);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.show', $invoice)->with('success', __('app.quote_converted', ['number' => $quote->number]));
    }

    public function destroy(Quote $quote, DeleteQuoteAction $delete): RedirectResponse
    {
        $this->authorize('delete', $quote);

        try {
            $delete->execute($quote);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('quotes.index')->with('success', __('app.quote_deleted'));
    }

    public function pdf(Quote $quote, GenerateQuotePdfAction $pdf, CurrentOrganization $currentOrg): HttpResponse
    {
        $this->authorize('view', $quote);

        $organization = $currentOrg->get();
        $content = $pdf->execute($quote, $organization, $organization->locale ?? app()->getLocale());

        return new HttpResponse($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.QuoteQuery::fileName($quote).'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function formProps(Request $request): array
    {
        return [
            'customers' => ContactQuery::forSelect(),
            'vatRates' => VatRateQuery::active(),
            'defaultVatRateId' => optional(VatRateQuery::active()->firstWhere('is_default', true))->id,
            'taxTreatments' => InvoiceTaxTreatment::options(),
            'projectOptions' => InvoiceProjectInput::options($request),
        ];
    }

    /**
     * The job chosen on the form: an id, null to clear, or false when the
     * projects module is off or the user may not allocate.
     */
    private function projectFrom(Request $request, ?Quote $quote = null): int|false|null
    {
        if (InvoiceProjectInput::options($request) === null || ! $request->exists('project_id')) {
            return false;
        }

        $validated = $request->validate([
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('cost_centers', 'id')
                    ->where('organization_id', app(CurrentOrganization::class)->id())
                    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $quote->cost_center_id ?? 0)),
            ],
        ]);

        return isset($validated['project_id']) ? (int) $validated['project_id'] : null;
    }
}
