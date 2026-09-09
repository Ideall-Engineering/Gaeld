<?php

namespace App\Domains\Banking\Models;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Enums\BankRuleAction;
use App\Domains\Banking\Enums\BankRuleDirection;
use App\Domains\Banking\Enums\BankRuleMatchField;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Organizations\Models\Organization;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An editable posting rule for imported bank transactions.
 *
 * A rule says: when this text shows up in this part of a transaction going in
 * this direction, book it to this account under this VAT treatment — and either
 * propose that to a human or, once trusted, apply it directly.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $match_text
 * @property BankRuleMatchField $match_field
 * @property BankRuleDirection $direction
 * @property string $account_code
 * @property ExpenseTaxTreatment $tax_treatment
 * @property int|null $vat_rate_id
 * @property int $priority
 * @property BankRuleAction $action
 * @property bool $is_active
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read VatRate|null $vatRate
 * @property-read Collection<int, BankRuleApplication> $applications
 */
class BankRule extends Model
{
    use Auditable, BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'match_text',
        'match_field',
        'direction',
        'account_code',
        'tax_treatment',
        'vat_rate_id',
        'priority',
        'action',
        'is_active',
        'valid_from',
        'valid_until',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'match_field' => BankRuleMatchField::class,
            'direction' => BankRuleDirection::class,
            'tax_treatment' => ExpenseTaxTreatment::class,
            'action' => BankRuleAction::class,
            'is_active' => 'boolean',
            'priority' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<VatRate, $this> */
    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    /** @return HasMany<BankRuleApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(BankRuleApplication::class);
    }

    /**
     * Rules eligible to run on a transaction dated $date, best candidate first.
     *
     * Ordering is by priority ascending — 10 beats 100 — so a specific rule can
     * be placed in front of a general one without renumbering the rest. Ties
     * fall back to the longer search text, on the reasoning that
     * "Google Cloud EMEA" is more specific than "Google".
     *
     * @param  Builder<BankRule>  $query
     * @return Builder<BankRule>
     */
    public function scopeEligibleOn(Builder $query, string $organizationId, string $date): Builder
    {
        return $query
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->orderBy('priority')
            ->orderByRaw('LENGTH(match_text) DESC');
    }

    /**
     * Does this rule's search text occur in the given transaction?
     *
     * Matching is case-insensitive and accent-insensitive on a normalized
     * string, because CAMT counterparty names arrive in whatever casing and
     * spacing the sending bank felt like using.
     */
    public function matches(BankTransaction $transaction): bool
    {
        if (! $this->direction->matches($transaction->type)) {
            return false;
        }

        $needle = self::normalize($this->match_text);

        if ($needle === '') {
            return false;
        }

        foreach ($this->match_field->haystack($transaction) as $candidate) {
            if (str_contains(self::normalize($candidate), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase, strip accents, collapse whitespace. */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        if ($transliterated !== false) {
            $value = strtolower((string) preg_replace('/[^a-z0-9\s]/i', '', $transliterated));
        }

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
