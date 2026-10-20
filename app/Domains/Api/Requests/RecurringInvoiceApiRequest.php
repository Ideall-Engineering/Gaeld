<?php

namespace App\Domains\Api\Requests;

use App\Domains\Api\Requests\Concerns\NamesLineVatRates;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\RecurringInvoice;
use App\Domains\Invoicing\Support\DocumentContactPerson;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) or change (PATCH, fields optional) a recurring invoice. The
 * customer and VAT rates are named as in the rest of the API; the template
 * lines take `vat_rate_code` too.
 */
class RecurringInvoiceApiRequest extends FormRequest
{
    use NamesLineVatRates;

    public function authorize(): bool
    {
        $recurring = $this->route('recurringInvoice');

        return $recurring instanceof RecurringInvoice
            ? ($this->user()?->can('update', $recurring) ?? false)
            : ($this->user()?->can('create', RecurringInvoice::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $org = app(CurrentOrganization::class);
        $orgId = $org->isBound() ? $org->id() : '0';
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'customer_id' => [$required, 'uuid', Rule::exists('contacts', 'uuid')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'frequency' => [$required, 'string', 'in:weekly,monthly,quarterly,yearly'],
            'next_issue_date' => [$required, 'date'],
            'end_date' => ['nullable', 'date', 'after:next_issue_date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'contact_person_id' => ['nullable', 'uuid', DocumentContactPerson::rule(fn () => $this->customer())],
            'lines' => [$required, 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.vat_rate_id' => ['nullable', 'uuid', Rule::exists('vat_rates', 'uuid')->where('organization_id', $orgId)],
            ...$this->lineVatRules(),
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

    /** The customer the request names, or the one of the recurring invoice it changes. */
    public function customer(): ?Contact
    {
        if ($this->filled('customer_id')) {
            return Contact::query()->where('uuid', $this->input('customer_id'))->first();
        }
        $recurring = $this->route('recurringInvoice');

        return $recurring instanceof RecurringInvoice ? $recurring->customer : null;
    }
}
