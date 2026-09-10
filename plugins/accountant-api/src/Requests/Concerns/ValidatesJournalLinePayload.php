<?php

namespace Plugins\AccountantApi\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared line-payload rules for the explicit-or-VAT-shorthand format,
 * mirrored from `App\Domains\Api\Requests\StoreJournalEntryApiRequest` so a
 * correction's replacement accepts the identical two formats journal
 * creation does (plan.md "im bestehenden expliziten oder
 * MWST-Kurzformat"). No core import: this concern only knows about request
 * input shape, not any Accounting model or enum.
 */
trait ValidatesJournalLinePayload
{
    /** Figures of the FTA return a zero-rated turnover can be deducted under. */
    private const DEDUCTION_FIGURES = ['220', '221', '225', '230', '235', '280'];

    private function usesLineShorthand(): bool
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
    private function linePayloadRules(string $organizationId): array
    {
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
            'lines.*.account_code' => $accountRule,
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];

        if ($this->usesLineShorthand()) {
            $rules['lines.*.contra_account_code'] = $accountRule;
            $rules['lines.*.gross'] = $amountRule;
            $rules['lines.*.vat_code'] = ['required', 'string', 'max:8'];
            $rules['lines.*.vat_figure'] = ['nullable', 'string', Rule::in(self::DEDUCTION_FIGURES)];

            return $rules;
        }

        $rules['lines.*.debit'] = $amountRule;
        $rules['lines.*.credit'] = $amountRule;
        $rules['lines.*.vat_type'] = ['nullable', 'string'];
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

    private function addLineBalanceErrors(Validator $validator): void
    {
        $shorthand = $this->usesLineShorthand();

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

            $hasDebit = isset($line['debit']) && (float) $line['debit'] > 0;
            $hasCredit = isset($line['credit']) && (float) $line['credit'] > 0;

            if ($hasDebit === $hasCredit) {
                $validator->errors()->add(
                    "lines.{$index}",
                    'Each line must contain a positive debit or credit, but not both.',
                );
            }
        }
    }
}
