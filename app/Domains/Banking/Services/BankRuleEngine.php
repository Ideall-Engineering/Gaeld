<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Enums\BankRuleAction;
use App\Domains\Banking\Enums\BankRuleOutcome;
use App\Domains\Banking\Models\BankRule;
use App\Domains\Banking\Models\BankRuleApplication;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Users\Models\User;
use App\Support\Exceptions\FeatureDisabledException;
use App\Support\FeatureFlag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies the organization's editable BankRules to imported transactions.
 *
 * The engine only ever *proposes*. Even a rule marked auto_apply lands as a
 * pending proposal unless the operator has explicitly switched on
 * `features.rule_engine_auto_apply`, which ships off. That split is deliberate:
 * the rule table has to be able to express "this one is safe to automate" long
 * before the installation is willing to act on it, otherwise there is no way to
 * pilot automation without risking the ledger.
 *
 * Distinct from the legacy RuleEngineService, which runs three hard-coded rules
 * and writes directly. This one is driven by data and writes nothing but its
 * own audit trail.
 */
class BankRuleEngine
{
    private int $failureCount = 0;

    /**
     * Evaluate every eligible rule against a transaction and record the winner.
     *
     * Idempotent: a transaction that already carries an application is left
     * alone, so re-importing a statement or re-running the engine over a period
     * cannot produce duplicates or silently overwrite a human decision.
     *
     * @throws FeatureDisabledException when the rule engine is off
     */
    public function evaluate(BankTransaction $transaction): ?BankRuleApplication
    {
        $this->assertEnabled();

        if ($transaction->is_reconciled) {
            return null;
        }

        $existing = BankRuleApplication::where('bank_transaction_id', $transaction->id)->first();

        if ($existing) {
            return $existing;
        }

        $rule = $this->firstMatchingRule($transaction);

        if (! $rule) {
            return null;
        }

        return DB::transaction(fn () => BankRuleApplication::create([
            'organization_id' => $rule->organization_id,
            'bank_rule_id' => $rule->id,
            'bank_transaction_id' => $transaction->id,
            'matched_text' => $rule->match_text,
            'suggested_account_code' => $rule->account_code,
            'suggested_tax_treatment' => $rule->tax_treatment,
            'suggested_vat_rate_id' => $rule->vat_rate_id,
            'reason' => $rule->reason,
            'action' => $this->effectiveAction($rule),
            'confidence' => $this->confidenceFor($rule, $transaction),
            'outcome' => BankRuleOutcome::Pending,
        ]));
    }

    /**
     * Run the engine across a set of transactions.
     *
     * @param  iterable<BankTransaction>  $transactions
     * @return Collection<int, BankRuleApplication>
     */
    public function evaluateAll(iterable $transactions): Collection
    {
        $this->assertEnabled();

        /** @var Collection<int, BankRuleApplication> $applications */
        $applications = new Collection;
        $this->failureCount = 0;

        foreach ($transactions as $transaction) {
            try {
                $application = $this->evaluate($transaction);
            } catch (\Throwable $e) {
                // One malformed transaction must not abort a whole statement —
                // but a swallowed exception that repeats for every row is a bug,
                // not an outlier, so the count is exposed to the caller.
                $this->failureCount++;

                Log::error('BankRuleEngine: evaluation failed', [
                    'bank_transaction_id' => $transaction->id,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            if ($application) {
                $applications->push($application);
            }
        }

        return $applications;
    }

    /** How many transactions the last evaluateAll() run could not process. */
    public function lastFailureCount(): int
    {
        return $this->failureCount;
    }

    /**
     * Record what the human decided about a proposal.
     *
     * Passing values that differ from the suggestion marks the application as
     * Corrected rather than Confirmed — the distinction is the whole point of
     * the log, so it is derived here instead of being left to the caller.
     */
    public function decide(
        BankRuleApplication $application,
        User $user,
        ?string $accountCode = null,
        ?string $taxTreatment = null,
        ?int $vatRateId = null,
        bool $rejected = false,
    ): BankRuleApplication {
        $finalAccount = $accountCode ?? $application->suggested_account_code;
        $finalTreatment = $taxTreatment ?? $application->suggested_tax_treatment->value;
        $finalVatRate = $vatRateId ?? $application->suggested_vat_rate_id;

        $unchanged = $finalAccount === $application->suggested_account_code
            && $finalTreatment === $application->suggested_tax_treatment->value
            && $finalVatRate === $application->suggested_vat_rate_id;

        $outcome = match (true) {
            $rejected => BankRuleOutcome::Rejected,
            $unchanged => BankRuleOutcome::Confirmed,
            default => BankRuleOutcome::Corrected,
        };

        $application->update([
            'outcome' => $outcome,
            'final_account_code' => $rejected ? null : $finalAccount,
            'final_tax_treatment' => $rejected ? null : $finalTreatment,
            'final_vat_rate_id' => $rejected ? null : $finalVatRate,
            'decided_by' => $user->id,
            'decided_at' => now(),
        ]);

        return $application->fresh();
    }

    /**
     * How often a rule's proposals have been accepted exactly as made.
     *
     * The basis for deciding whether a rule has earned auto_apply. Corrections
     * and rejections both count against it.
     *
     * @return array{total: int, confirmed: int, corrected: int, rejected: int, accuracy: float|null}
     */
    public function trackRecord(BankRule $rule): array
    {
        $counts = BankRuleApplication::where('bank_rule_id', $rule->id)
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $confirmed = (int) ($counts[BankRuleOutcome::Confirmed->value] ?? 0);
        $corrected = (int) ($counts[BankRuleOutcome::Corrected->value] ?? 0);
        $rejected = (int) ($counts[BankRuleOutcome::Rejected->value] ?? 0);
        $decided = $confirmed + $corrected + $rejected;

        return [
            'total' => $decided,
            'confirmed' => $confirmed,
            'corrected' => $corrected,
            'rejected' => $rejected,
            'accuracy' => $decided > 0 ? round($confirmed / $decided, 4) : null,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────────────────────

    /**
     * Which rule would win for this transaction, without recording anything.
     *
     * Used by the dry-run path of gaeld:apply-bank-rules so a rule set can be
     * tried against real statements before it is allowed to leave a trace.
     */
    public function previewRuleFor(BankTransaction $transaction): ?BankRule
    {
        return $this->firstMatchingRule($transaction);
    }

    private function firstMatchingRule(BankTransaction $transaction): ?BankRule
    {
        // bank_transactions.bank_account_id is NOT NULL behind a foreign key, so
        // the owning organization is always reachable from the transaction.
        // loadMissing rather than plain property access: the app runs with lazy
        // loading disabled, and the engine must work for callers that did not
        // think to eager-load.
        $transaction->loadMissing('bankAccount');
        $organizationId = $transaction->bankAccount->organization_id;

        $rules = BankRule::query()
            ->eligibleOn($organizationId, $transaction->date->toDateString())
            ->get();

        foreach ($rules as $rule) {
            if ($rule->matches($transaction)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Downgrade auto_apply to suggest while automatic posting stays switched off.
     */
    private function effectiveAction(BankRule $rule): BankRuleAction
    {
        if ($rule->action === BankRuleAction::AutoApply && ! self::autoApplyAllowed()) {
            return BankRuleAction::Suggest;
        }

        return $rule->action;
    }

    /**
     * A coarse confidence score, used only for ordering and reporting.
     *
     * An exact counterparty match scores higher than a substring buried in a
     * free-text description, because the former is far less likely to be
     * coincidental.
     */
    private function confidenceFor(BankRule $rule, BankTransaction $transaction): int
    {
        $needle = BankRule::normalize($rule->match_text);
        $counterparty = BankRule::normalize(
            trim((string) $transaction->creditor_name.' '.(string) $transaction->debtor_name)
        );

        if ($counterparty !== '' && $counterparty === $needle) {
            return 95;
        }

        if ($counterparty !== '' && str_contains($counterparty, $needle)) {
            return 85;
        }

        return 70;
    }

    public static function autoApplyAllowed(): bool
    {
        return (bool) config('features.rule_engine_auto_apply', false);
    }

    private function assertEnabled(): void
    {
        if (FeatureFlag::disabled('rule_engine')) {
            throw new FeatureDisabledException('rule_engine');
        }
    }
}
