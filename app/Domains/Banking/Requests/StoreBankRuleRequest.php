<?php

namespace App\Domains\Banking\Requests;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Banking\Enums\BankRuleAction;
use App\Domains\Banking\Enums\BankRuleDirection;
use App\Domains\Banking\Enums\BankRuleMatchField;
use App\Domains\Banking\Models\BankRule;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBankRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        // route() is typed object|string, so narrow before reaching for the key.
        $routeRule = $this->route('bankRule');
        $ruleId = $routeRule instanceof BankRule ? $routeRule->id : null;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('bank_rules', 'name')
                    ->where('organization_id', $organizationId)
                    ->ignore($ruleId),
            ],
            'match_text' => ['required', 'string', 'max:255'],
            'match_field' => ['required', Rule::enum(BankRuleMatchField::class)],
            'direction' => ['required', Rule::enum(BankRuleDirection::class)],
            'account_code' => [
                'required', 'string', 'max:10',
                Rule::exists('accounts', 'code')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'tax_treatment' => ['required', Rule::enum(ExpenseTaxTreatment::class)],
            'vat_rate_id' => [
                'nullable', 'integer',
                Rule::exists('vat_rates', 'id')->where('organization_id', $organizationId),
            ],
            'priority' => ['required', 'integer', 'min:1', 'max:9999'],
            'action' => ['required', Rule::enum(BankRuleAction::class)],
            'is_active' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $treatment = ExpenseTaxTreatment::tryFrom((string) $this->input('tax_treatment'));

            // A rule that claims Swiss input tax or acquisition tax without
            // naming a rate cannot produce a bookable proposal.
            if ($treatment
                && in_array($treatment, [ExpenseTaxTreatment::Standard, ExpenseTaxTreatment::ReverseCharge], true)
                && $this->input('vat_rate_id') === null) {
                $validator->errors()->add('vat_rate_id', __('app.bank_rule_vat_rate_required'));
            }

            // Booking a purchase to a revenue or balance sheet account is almost
            // always a slip, and one that is tedious to unpick afterwards.
            $code = (string) $this->input('account_code');

            // value() returns the cast enum, not the raw column, so compare on
            // the enum rather than on strings.
            $type = Account::where('organization_id', app(CurrentOrganization::class)->id())
                ->where('code', $code)
                ->value('type');

            if ($type !== null && ! in_array($type, [AccountType::Expense, AccountType::Revenue], true)) {
                $validator->errors()->add('account_code', __('app.bank_rule_account_must_be_profit_and_loss'));
            }
        });
    }
}
