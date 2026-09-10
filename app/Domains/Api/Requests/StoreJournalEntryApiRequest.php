<?php

namespace App\Domains\Api\Requests;

use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreJournalEntryApiRequest extends FormRequest
{
    /** Figures of the FTA return a zero-rated turnover can be deducted under. */
    private const DEDUCTION_FIGURES = ['220', '221', '225', '230', '235', '280'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', JournalEntry::class) ?? false;
    }

    /**
     * A line written in shorthand carries a VAT code and a gross amount instead
     * of an explicit debit and credit. One request uses one form or the other.
     */
    public function usesShorthand(): bool
    {
        foreach ($this->input('lines', []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            if (isset($line['vat_code']) || isset($line['gross']) || isset($line['contra_account_code'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currentOrganization = app(CurrentOrganization::class);
        $organizationId = $currentOrganization->isBound() ? $currentOrganization->id() : '0';
        $amountRule = ['required', 'string', 'regex:/^\d{1,11}(?:\.\d{1,2})?$/'];
        $accountRule = [
            'required',
            'string',
            'max:10',
            Rule::exists('accounts', 'code')
                ->where('organization_id', $organizationId)
                ->where('is_active', true),
        ];

        $rules = [
            'date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'posted'])],
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.account_code' => $accountRule,
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];

        if ($this->usesShorthand()) {
            // A shorthand line is a complete entry on its own.
            $rules['lines'][] = 'min:1';
            $rules['lines.*.contra_account_code'] = $accountRule;
            $rules['lines.*.gross'] = $amountRule;
            // Deliberately only a string: an unknown code is answered with the
            // stable error code `unknown_vat_code`, not a validation message.
            $rules['lines.*.vat_code'] = ['required', 'string', 'max:8'];
            $rules['lines.*.vat_figure'] = ['nullable', 'string', Rule::in(self::DEDUCTION_FIGURES)];

            return $rules;
        }

        $rules['lines'][] = 'min:2';
        $rules['lines.*.debit'] = $amountRule;
        $rules['lines.*.credit'] = $amountRule;
        $rules['lines.*.vat_type'] = ['nullable', Rule::enum(VatEntryType::class)];
        $rules['lines.*.vat_rate_id'] = [
            'nullable',
            'required_with:lines.*.vat_type',
            'uuid',
            Rule::exists('vat_rates', 'uuid')->where('organization_id', $organizationId),
        ];
        $rules['lines.*.vat_amount'] = ['nullable', 'string', 'regex:/^\d{1,11}(?:\.\d{1,2})?$/'];
        $rules['lines.*.vat_figure'] = ['nullable', 'string', Rule::in(self::DEDUCTION_FIGURES)];

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $shorthand = $this->usesShorthand();

            foreach ($this->input('lines', []) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                if ($shorthand) {
                    if (isset($line['debit']) || isset($line['credit'])) {
                        $validator->errors()->add(
                            "lines.{$index}",
                            'A line uses either the shorthand form (gross and vat_code) or explicit debit and credit, not both.',
                        );
                    }

                    continue;
                }

                $debit = (string) ($line['debit'] ?? '0');
                $credit = (string) ($line['credit'] ?? '0');

                if (Money::isPositive($debit) === Money::isPositive($credit)) {
                    $validator->errors()->add(
                        "lines.{$index}",
                        'Each line must contain a positive debit or credit, but not both.',
                    );
                }
            }
        }];
    }
}
