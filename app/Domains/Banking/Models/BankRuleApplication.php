<?php

namespace App\Domains\Banking\Models;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Enums\BankRuleAction;
use App\Domains\Banking\Enums\BankRuleOutcome;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a rule proposed for one transaction, and what the human decided.
 *
 * The suggested_* columns are a snapshot: editing or deleting the rule later
 * must not change what the record says was proposed at the time. The final_*
 * columns hold the decision, which is what makes the difference between a rule
 * that is trusted and one that is merely tolerated measurable.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $bank_rule_id
 * @property int $bank_transaction_id
 * @property string|null $matched_text
 * @property string $suggested_account_code
 * @property ExpenseTaxTreatment $suggested_tax_treatment
 * @property int|null $suggested_vat_rate_id
 * @property string|null $reason
 * @property BankRuleAction $action
 * @property int $confidence
 * @property BankRuleOutcome $outcome
 * @property string|null $final_account_code
 * @property ExpenseTaxTreatment|null $final_tax_treatment
 * @property int|null $final_vat_rate_id
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property-read BankRule|null $rule
 * @property-read BankTransaction $transaction
 */
class BankRuleApplication extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'bank_rule_id',
        'bank_transaction_id',
        'matched_text',
        'suggested_account_code',
        'suggested_tax_treatment',
        'suggested_vat_rate_id',
        'reason',
        'action',
        'confidence',
        'outcome',
        'final_account_code',
        'final_tax_treatment',
        'final_vat_rate_id',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'suggested_tax_treatment' => ExpenseTaxTreatment::class,
            'final_tax_treatment' => ExpenseTaxTreatment::class,
            'action' => BankRuleAction::class,
            'outcome' => BankRuleOutcome::class,
            'confidence' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<BankRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(BankRule::class, 'bank_rule_id');
    }

    /** @return BelongsTo<BankTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    /** @return BelongsTo<VatRate, $this> */
    public function suggestedVatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class, 'suggested_vat_rate_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Was the proposal accepted exactly as made?
     *
     * Only an untouched confirmation counts as evidence that the rule is right.
     */
    public function wasAcceptedUnchanged(): bool
    {
        return $this->outcome === BankRuleOutcome::Confirmed;
    }
}
