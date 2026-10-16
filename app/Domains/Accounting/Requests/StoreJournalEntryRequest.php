<?php

namespace App\Domains\Accounting\Requests;

use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $journalEntry = $this->route('journalEntry');

        if (! $journalEntry instanceof JournalEntry) {
            return $this->user()?->can('create', JournalEntry::class) ?? false;
        }

        return $this->user()?->can($journalEntry->is_posted ? 'amend' : 'update', $journalEntry) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = app(CurrentOrganization::class)->id();

        return [
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_posted' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('organization_id', $orgId)
                    ->where('is_active', true),
            ],
            'lines.*.debit' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.credit' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            ...self::lineVatRules($orgId),
            // Only read when a posted entry is amended in place.
            'revision' => ['nullable', 'integer', 'min:1'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
            'acknowledge_bank_amount_change' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validation for the VAT fields of a journal line.
     *
     * VAT travels with the line so that editing an entry, a draft or a
     * correction's replacement never silently drops it.
     *
     * @return array<string, mixed>
     */
    public static function lineVatRules(string $organizationId): array
    {
        return [
            'lines.*.vat_type' => ['nullable', Rule::enum(VatEntryType::class)],
            'lines.*.vat_rate_id' => [
                'nullable',
                'required_with:lines.*.vat_type',
                'uuid',
                Rule::exists('vat_rates', 'uuid')->where('organization_id', $organizationId),
            ],
            'lines.*.vat_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.vat_figure' => ['nullable', 'string', 'max:10'],
        ];
    }
}
