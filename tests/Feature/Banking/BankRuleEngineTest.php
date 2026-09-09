<?php

namespace Tests\Feature\Banking;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Enums\BankRuleAction;
use App\Domains\Banking\Enums\BankRuleDirection;
use App\Domains\Banking\Enums\BankRuleMatchField;
use App\Domains\Banking\Enums\BankRuleOutcome;
use App\Domains\Banking\Enums\BankTransactionType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankRule;
use App\Domains\Banking\Models\BankRuleApplication;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Banking\Services\BankRuleEngine;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Support\Exceptions\FeatureDisabledException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class BankRuleEngineTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private BankAccount $bankAccount;

    private VatRate $vatRate;

    private BankRuleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Config::set('features.rule_engine', true);
        Config::set('features.rule_engine_auto_apply', false);

        foreach ([['1020', AccountType::Asset], ['6530', AccountType::Expense], ['6950', AccountType::Expense]] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->org->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type->value,
            ]);
        }

        $this->vatRate = VatRate::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard',
            'rate' => 8.10,
            'code' => 'NORMAL',
            'is_default' => true,
        ]);

        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'CHF Konto',
            'iban' => 'CH56 0483 5012 3456 7800 9',
            'currency' => 'CHF',
            'is_active' => true,
        ]);

        $this->engine = app(BankRuleEngine::class);
    }

    private function makeRule(array $overrides = []): BankRule
    {
        return BankRule::create(array_merge([
            'organization_id' => $this->org->id,
            'name' => 'Anthropic',
            'match_text' => 'Anthropic',
            'match_field' => BankRuleMatchField::Counterparty->value,
            'direction' => BankRuleDirection::Debit->value,
            'account_code' => '6530',
            'tax_treatment' => ExpenseTaxTreatment::ReverseCharge->value,
            'vat_rate_id' => $this->vatRate->id,
            'priority' => 10,
            'action' => BankRuleAction::Suggest->value,
            'is_active' => true,
            'reason' => 'Auslandleistung — Bezugsteuer',
        ], $overrides));
    }

    private function makeTransaction(array $overrides = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'bank_account_id' => $this->bankAccount->id,
            'date' => '2026-01-15',
            'description' => 'Kartenzahlung',
            'amount' => 120.00,
            'type' => BankTransactionType::Debit->value,
            'creditor_name' => 'ANTHROPIC PBC',
            'is_reconciled' => false,
        ], $overrides));
    }

    // ──────────────────────────────────────────────────────────────
    //  Matching
    // ──────────────────────────────────────────────────────────────

    public function test_it_suggests_the_account_and_treatment_of_the_matching_rule(): void
    {
        $rule = $this->makeRule();
        $transaction = $this->makeTransaction();

        $application = $this->engine->evaluate($transaction);

        $this->assertNotNull($application);
        $this->assertSame($rule->id, $application->bank_rule_id);
        $this->assertSame('6530', $application->suggested_account_code);
        $this->assertSame(ExpenseTaxTreatment::ReverseCharge, $application->suggested_tax_treatment);
        $this->assertSame($this->vatRate->id, $application->suggested_vat_rate_id);
        $this->assertSame('Auslandleistung — Bezugsteuer', $application->reason);
        $this->assertSame(BankRuleOutcome::Pending, $application->outcome);
    }

    public function test_matching_ignores_case_and_accents(): void
    {
        $this->makeRule(['match_text' => 'Zürich Versicherung']);
        $transaction = $this->makeTransaction(['creditor_name' => 'ZURICH VERSICHERUNG AG']);

        $this->assertNotNull($this->engine->evaluate($transaction));
    }

    public function test_a_rule_for_the_other_direction_does_not_match(): void
    {
        $this->makeRule(['direction' => BankRuleDirection::Credit->value]);
        $transaction = $this->makeTransaction();

        $this->assertNull($this->engine->evaluate($transaction));
    }

    public function test_a_description_rule_does_not_read_the_counterparty(): void
    {
        $this->makeRule([
            'match_field' => BankRuleMatchField::Description->value,
            'match_text' => 'Anthropic',
        ]);
        $transaction = $this->makeTransaction([
            'description' => 'Kontoführungsgebühr',
            'creditor_name' => 'ANTHROPIC PBC',
        ]);

        $this->assertNull($this->engine->evaluate($transaction));
    }

    public function test_inactive_and_expired_rules_are_skipped(): void
    {
        $this->makeRule(['name' => 'Inaktiv', 'is_active' => false]);
        $this->makeRule(['name' => 'Abgelaufen', 'valid_until' => '2025-12-31']);
        $this->makeRule(['name' => 'Noch nicht gültig', 'valid_from' => '2026-06-01']);

        $this->assertNull($this->engine->evaluate($this->makeTransaction()));
    }

    public function test_a_rule_inside_its_validity_window_applies(): void
    {
        $this->makeRule(['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);

        $this->assertNotNull($this->engine->evaluate($this->makeTransaction()));
    }

    public function test_the_lower_priority_number_wins(): void
    {
        $this->makeRule(['name' => 'Allgemein', 'match_text' => 'Anthropic', 'account_code' => '6950', 'priority' => 50]);
        $this->makeRule(['name' => 'Spezifisch', 'match_text' => 'Anthropic', 'account_code' => '6530', 'priority' => 10]);

        $application = $this->engine->evaluate($this->makeTransaction());

        $this->assertSame('6530', $application->suggested_account_code);
    }

    // ──────────────────────────────────────────────────────────────
    //  Safety
    // ──────────────────────────────────────────────────────────────

    public function test_evaluating_the_same_transaction_twice_creates_one_application(): void
    {
        $this->makeRule();
        $transaction = $this->makeTransaction();

        $first = $this->engine->evaluate($transaction);
        $second = $this->engine->evaluate($transaction);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BankRuleApplication::where('bank_transaction_id', $transaction->id)->count());
    }

    public function test_a_reconciled_transaction_is_left_alone(): void
    {
        $this->makeRule();
        $transaction = $this->makeTransaction(['is_reconciled' => true]);

        $this->assertNull($this->engine->evaluate($transaction));
    }

    public function test_auto_apply_is_downgraded_to_a_suggestion_while_automation_is_off(): void
    {
        $this->makeRule(['action' => BankRuleAction::AutoApply->value]);

        $application = $this->engine->evaluate($this->makeTransaction());

        $this->assertSame(BankRuleAction::Suggest, $application->action);
        $this->assertSame(BankRuleOutcome::Pending, $application->outcome);
    }

    public function test_auto_apply_is_kept_once_automation_is_switched_on(): void
    {
        Config::set('features.rule_engine_auto_apply', true);
        $this->makeRule(['action' => BankRuleAction::AutoApply->value]);

        $application = $this->engine->evaluate($this->makeTransaction());

        $this->assertSame(BankRuleAction::AutoApply, $application->action);
    }

    public function test_the_engine_refuses_to_run_when_the_feature_is_off(): void
    {
        Config::set('features.rule_engine', false);
        $this->makeRule();

        $this->expectException(FeatureDisabledException::class);
        $this->engine->evaluate($this->makeTransaction());
    }

    public function test_a_batch_yields_applications_only_for_the_transactions_that_matched(): void
    {
        $this->makeRule();

        $matching = $this->makeTransaction();
        $unmatched = $this->makeTransaction(['creditor_name' => 'SBB CFF FFS', 'reference' => 'x']);
        $reconciled = $this->makeTransaction(['reference' => 'y', 'is_reconciled' => true]);

        $applications = $this->engine->evaluateAll([$matching, $unmatched, $reconciled]);

        $this->assertCount(1, $applications);
        $this->assertSame($matching->id, $applications->first()->bank_transaction_id);
    }

    // ──────────────────────────────────────────────────────────────
    //  The decision log
    // ──────────────────────────────────────────────────────────────

    public function test_accepting_a_suggestion_unchanged_counts_as_confirmed(): void
    {
        $this->makeRule();
        $application = $this->engine->evaluate($this->makeTransaction());

        $decided = $this->engine->decide($application, $this->user);

        $this->assertSame(BankRuleOutcome::Confirmed, $decided->outcome);
        $this->assertSame('6530', $decided->final_account_code);
        $this->assertSame($this->user->id, $decided->decided_by);
        $this->assertNotNull($decided->decided_at);
    }

    public function test_changing_the_account_counts_as_corrected(): void
    {
        $this->makeRule();
        $application = $this->engine->evaluate($this->makeTransaction());

        $decided = $this->engine->decide($application, $this->user, accountCode: '6950');

        $this->assertSame(BankRuleOutcome::Corrected, $decided->outcome);
        $this->assertSame('6950', $decided->final_account_code);

        // The proposal itself must survive the correction untouched.
        $this->assertSame('6530', $decided->suggested_account_code);
    }

    public function test_changing_only_the_vat_treatment_also_counts_as_corrected(): void
    {
        $this->makeRule();
        $application = $this->engine->evaluate($this->makeTransaction());

        $decided = $this->engine->decide(
            $application,
            $this->user,
            taxTreatment: ExpenseTaxTreatment::Standard->value,
        );

        $this->assertSame(BankRuleOutcome::Corrected, $decided->outcome);
    }

    public function test_editing_the_rule_afterwards_does_not_rewrite_the_log(): void
    {
        $rule = $this->makeRule();
        $application = $this->engine->evaluate($this->makeTransaction());

        $rule->update(['account_code' => '6950', 'tax_treatment' => ExpenseTaxTreatment::None->value]);

        $application->refresh();
        $this->assertSame('6530', $application->suggested_account_code);
        $this->assertSame(ExpenseTaxTreatment::ReverseCharge, $application->suggested_tax_treatment);
    }

    public function test_the_track_record_separates_confirmations_from_corrections(): void
    {
        $rule = $this->makeRule();

        foreach (['a', 'b', 'c'] as $marker) {
            $transaction = $this->makeTransaction(['reference' => $marker]);
            $application = $this->engine->evaluate($transaction);

            $marker === 'c'
                ? $this->engine->decide($application, $this->user, accountCode: '6950')
                : $this->engine->decide($application, $this->user);
        }

        $record = $this->engine->trackRecord($rule);

        $this->assertSame(3, $record['total']);
        $this->assertSame(2, $record['confirmed']);
        $this->assertSame(1, $record['corrected']);
        $this->assertEqualsWithDelta(0.6667, $record['accuracy'], 0.0001);
    }

    public function test_a_rule_without_decisions_has_no_accuracy(): void
    {
        $rule = $this->makeRule();

        $this->assertNull($this->engine->trackRecord($rule)['accuracy']);
    }
}
