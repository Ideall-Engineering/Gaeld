<?php

namespace App\Domains\Api\Requests;

use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Rules\NotSystemReference;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Support\AllocationRules;
use App\Domains\Projects\Support\ApiAllocations;
use App\Domains\Projects\Support\ProjectsModule;
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
            'reference' => [
                'nullable',
                'string',
                'max:100',
                new NotSystemReference($this->route('journalEntry') instanceof JournalEntry ? $this->route('journalEntry')->reference : null),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'document_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'status' => ['required', Rule::in(['draft', 'posted'])],
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.account_code' => $accountRule,
            'lines.*.description' => ['nullable', 'string', 'max:255'],
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
            // A shorthand line becomes several lines; which of them a
            // project share belongs to would be a guess (spec 009).
            if ($currentOrganization->isBound() && ProjectsModule::enabledFor($currentOrganization->get())) {
                $rules['lines.*.allocations'] = ['prohibited'];
                if ($this->isMethod('POST')) {
                    $rules = [...$rules, ...self::acquisitionRules($organizationId)];
                }
            }

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

        if ($currentOrganization->isBound() && ProjectsModule::enabledFor($currentOrganization->get()) && $this->isMethod('POST')) {
            $rules = [...$rules, ...self::acquisitionRules($organizationId)];
        }

        if ($currentOrganization->isBound() && ProjectsModule::enabledFor($currentOrganization->get())) {
            $entry = $this->route('journalEntry');
            $entry = $entry instanceof JournalEntry ? $entry : null;
            $rules = [
                ...$rules,
                ...ApiAllocations::rules($organizationId, 'lines.*.allocations', AllocationRules::projectsOf($entry), AllocationRules::employeesOf($entry)),
            ];
        }

        return $rules;
    }

    /**
     * A purchase says with its booking whether it is an acquisition: the
     * articles bought for people, or no_acquisition for general costs.
     *
     * @return array<string, mixed>
     */
    private static function acquisitionRules(string $organizationId): array
    {
        return [
            'no_acquisition' => ['sometimes', 'boolean'],
            'acquisitions' => ['sometimes', 'array', 'min:1', 'max:50', 'prohibited_if:no_acquisition,true'],
            'acquisitions.*.item' => ['required', 'string', 'max:255'],
            'acquisitions.*.category' => ['nullable', 'string', 'max:60'],
            'acquisitions.*.supplier' => ['nullable', 'string', 'max:120'],
            'acquisitions.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'acquisitions.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'acquisitions.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'acquisitions.*.net_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'acquisitions.*.return_until' => ['nullable', 'date'],
            'acquisitions.*.warranty_until' => ['nullable', 'date'],
            'acquisitions.*.notes' => ['nullable', 'string', 'max:5000'],
            'acquisitions.*.people' => ['required', 'array', 'min:1', 'max:10'],
            'acquisitions.*.people.*.employee_id' => ['required', 'uuid', Rule::exists('employees', 'id')->where('organization_id', $organizationId)],
            'acquisitions.*.people.*.share' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('acquisitions', []) as $index => $article) {
                $total = array_sum(array_map(fn ($p) => (float) ($p['share'] ?? 0), (array) ($article['people'] ?? [])));
                if (is_array($article) && abs($total - 100) > 0.0001) {
                    $validator->errors()->add("acquisitions.{$index}.people", __('app.acquisition_shares_total'));
                }
            }

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
