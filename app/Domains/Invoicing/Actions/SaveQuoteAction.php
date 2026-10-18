<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\DTOs\QuoteData;
use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Exceptions\InvalidQuoteStateException;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Models\QuoteLine;
use App\Domains\Invoicing\Services\QuoteNumberGenerator;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates a quote or changes a draft one, with its lines and totals.
 *
 * Lines are calculated as invoice lines are (see SyncInvoiceLinesAction),
 * so the invoice made from the quote comes to the same total.
 */
class SaveQuoteAction
{
    private const MAX_NUMBER_RETRIES = 5;

    public function __construct(private QuoteNumberGenerator $numbers) {}

    /**
     * @param  int|null  $projectId  the job the quote is for (spec 009)
     */
    public function create(QuoteData $data, ?int $projectId = null): Quote
    {
        $year = Carbon::parse($data->issueDate)->year;

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $projectId, $year): Quote {
                    $quote = new Quote([
                        'organization_id' => $data->organizationId,
                        'number' => $this->numbers->next($data->organizationId, $year),
                        'status' => QuoteStatus::Draft,
                        'cost_center_id' => $projectId,
                    ]);

                    return $this->fill($quote, $data);
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_NUMBER_RETRIES || ! str_contains($e->getMessage(), 'quotes_organization_id_number_unique')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  int|false|null  $projectId  false leaves the job as it is
     */
    public function update(Quote $quote, QuoteData $data, int|false|null $projectId = false): Quote
    {
        if (! $quote->status->isEditable()) {
            throw new InvalidQuoteStateException(__('app.quote_not_editable'));
        }

        return DB::transaction(function () use ($quote, $data, $projectId): Quote {
            if ($projectId !== false) {
                $quote->cost_center_id = $projectId;
            }
            $quote->lines()->delete();

            return $this->fill($quote, $data);
        });
    }

    private function fill(Quote $quote, QuoteData $data): Quote
    {
        $customer = $data->customerId === null
            ? null
            : Contact::withoutGlobalScope('organization')
                ->where('organization_id', $data->organizationId)
                ->findOrFail($data->customerId);
        $data->taxTreatment->validateCustomer($customer);

        $quote->fill([
            'customer_id' => $data->customerId,
            'customer_snapshot' => $customer?->toInvoiceSnapshot(),
            'tax_treatment' => $data->taxTreatment,
            'issue_date' => $data->issueDate,
            'valid_until' => $data->validUntil,
            'title' => $data->title,
            'currency' => $data->currency,
            'notes' => $data->notes,
            'payment_terms' => $data->paymentTerms,
        ]);
        $quote->save();

        $this->createLines($quote, $data->lines, $data->taxTreatment->appliesSwissVat());
        $quote->recalculate();

        return $quote->load('lines.vatRate');
    }

    /**
     * @param  array<int, InvoiceLineData>  $lines
     */
    private function createLines(Quote $quote, array $lines, bool $applyVat): void
    {
        $created = [];
        foreach ($lines as $index => $lineData) {
            $line = new QuoteLine([
                'quote_id' => $quote->id,
                'type' => $lineData->type->value,
                'discount_type' => $lineData->discountType,
                'description' => $lineData->description,
                'quantity' => $lineData->quantity,
                'unit_price' => $lineData->unitPrice,
                'vat_rate_id' => $applyVat ? $lineData->vatRateId : null,
                'sort_order' => $lineData->sortOrder ?? $index,
            ]);
            if (! ($lineData->type === InvoiceLineType::Discount && $lineData->discountType === 'percentage')) {
                $line->calculate();
            }
            $line->save();
            $created[] = $line;
        }

        // A percentage discount applies to the sum of the items.
        /** @var numeric-string $itemSubtotal */
        $itemSubtotal = (string) collect($created)
            ->filter(fn (QuoteLine $l) => $l->type === InvoiceLineType::Item)
            ->sum('amount');

        foreach ($created as $line) {
            if ($line->type === InvoiceLineType::Discount && $line->discount_type === 'percentage') {
                $line->amount = Money::percentage($itemSubtotal, (string) $line->unit_price);
                $vatRate = $applyVat ? $line->vatRate : null;
                $line->vat_amount = $vatRate ? Money::percentage($line->amount, (string) $vatRate->rate) : '0.00';
                $line->save();
            }
        }
    }
}
