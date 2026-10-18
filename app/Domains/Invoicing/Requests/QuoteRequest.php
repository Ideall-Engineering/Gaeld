<?php

namespace App\Domains\Invoicing\Requests;

use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or change a quote from the web form. The number is assigned by the
 * system; the customer may be left open on a draft.
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote
            ? $this->user()->can('update', $quote)
            : $this->user()->can('create', Quote::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = app(CurrentOrganization::class)->id();

        return [
            'customer_id' => ['nullable', Rule::exists('contacts', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'issue_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:issue_date',
            'title' => 'nullable|string|max:255',
            'currency' => 'string|size:3',
            'tax_treatment' => ['nullable', Rule::enum(InvoiceTaxTreatment::class)],
            'notes' => 'nullable|string',
            'payment_terms' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.type' => ['nullable', Rule::enum(InvoiceLineType::class)],
            'lines.*.discount_type' => ['nullable', 'in:flat,percentage'],
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required_unless:lines.*.type,text|nullable|numeric|min:0',
            'lines.*.unit_price' => 'required_unless:lines.*.type,text|nullable|numeric',
            'lines.*.vat_rate_id' => ['nullable', Rule::exists('vat_rates', 'id')->where('organization_id', $orgId)],
        ];
    }

    /**
     * The validated data in the shape QuoteData reads; text lines get
     * zero amounts.
     *
     * @return array<string, mixed>
     */
    public function quoteData(): array
    {
        $data = $this->validated();
        $data['organization_id'] = app(CurrentOrganization::class)->id();
        $data['lines'] = array_map(fn (array $line) => [
            ...$line,
            'quantity' => (string) ($line['quantity'] ?? '0'),
            'unit_price' => (string) ($line['unit_price'] ?? '0'),
        ], $data['lines']);

        return $data;
    }
}
