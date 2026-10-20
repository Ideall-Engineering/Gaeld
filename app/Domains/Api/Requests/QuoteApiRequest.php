<?php

namespace App\Domains\Api\Requests;

use App\Domains\Api\Requests\Concerns\NamesLineVatRates;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Support\DocumentFieldRules;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a quote (POST) or change a draft one (PATCH, fields optional).
 * Customer, VAT rate and project are referenced as in the rest of the API:
 * contacts and VAT rates by UUID, projects by id.
 */
class QuoteApiRequest extends FormRequest
{
    use NamesLineVatRates;

    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote
            ? ($this->user()?->can('update', $quote) ?? false)
            : ($this->user()?->can('create', Quote::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $org = app(CurrentOrganization::class);
        $orgId = $org->isBound() ? $org->id() : '0';
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'customer_id' => ['nullable', 'uuid', Rule::exists('contacts', 'uuid')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'issue_date' => [$required, 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'title' => 'nullable|string|max:255',
            'currency' => 'sometimes|string|size:3',
            'tax_treatment' => ['nullable', Rule::enum(InvoiceTaxTreatment::class)],
            'notes' => 'nullable|string',
            'payment_terms' => 'nullable|string|max:255',
            'project_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('organization_id', $orgId)],
            'lines' => [$required, 'array', 'min:1', 'max:500'],
            'lines.*.type' => ['nullable', Rule::enum(InvoiceLineType::class)],
            'lines.*.discount_type' => ['nullable', 'in:flat,percentage'],
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required_unless:lines.*.type,text|nullable|numeric|min:0',
            'lines.*.unit_price' => 'required_unless:lines.*.type,text|nullable|numeric',
            'lines.*.vat_rate_id' => ['nullable', 'uuid', Rule::exists('vat_rates', 'uuid')->where('organization_id', $orgId)],
            ...$this->lineVatRules(),
            ...DocumentFieldRules::for(fn () => $this->filled('customer_id')
                ? Contact::query()->where('uuid', $this->input('customer_id'))->first()
                : ($this->route('quote') instanceof Quote ? $this->route('quote')->customer : null)),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->resolveLineVatCodes();
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkLineVatRates($validator)];
    }

    protected function currentTaxTreatment(): ?string
    {
        $document = $this->route('quote');

        return $document instanceof Quote ? $document->tax_treatment->value : null;
    }
}
