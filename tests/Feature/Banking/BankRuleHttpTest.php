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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class BankRuleHttpTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private VatRate $vatRate;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Config::set('features.rule_engine', true);

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
            'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true,
        ]);

        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'CHF Konto',
            'iban' => 'CH56 0483 5012 3456 7800 9',
            'currency' => 'CHF',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
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
            'reason' => 'Auslandleistung',
        ], $overrides);
    }

    public function test_the_rule_list_is_reachable_when_the_feature_is_on(): void
    {
        $this->actAsOrg()->get('/banking/rules')->assertStatus(200);
    }

    public function test_the_rule_list_is_forbidden_when_the_feature_is_off(): void
    {
        Config::set('features.rule_engine', false);

        $this->actAsOrg()->get('/banking/rules')->assertStatus(403);
    }

    public function test_the_rules_path_is_not_swallowed_by_the_bank_account_route(): void
    {
        // /banking/{bankAccount} is registered for the same prefix; if it won,
        // this would try to resolve a bank account named "rules" and 404.
        $this->actAsOrg()->get('/banking/rules')->assertStatus(200);
        $this->actAsOrg()->get('/banking/rule-review')->assertStatus(200);
    }

    public function test_a_rule_can_be_created(): void
    {
        $this->actAsOrg()->post('/banking/rules', $this->payload())->assertRedirect();

        $rule = BankRule::firstOrFail();
        $this->assertSame('Anthropic', $rule->name);
        $this->assertSame(ExpenseTaxTreatment::ReverseCharge, $rule->tax_treatment);
        $this->assertSame($this->org->id, $rule->organization_id);
    }

    public function test_a_rule_claiming_input_tax_without_a_rate_is_rejected(): void
    {
        $this->actAsOrg()
            ->post('/banking/rules', $this->payload(['vat_rate_id' => null]))
            ->assertSessionHasErrors('vat_rate_id');

        $this->assertSame(0, BankRule::count());
    }

    public function test_a_rule_pointing_at_a_balance_sheet_account_is_rejected(): void
    {
        $this->actAsOrg()
            ->post('/banking/rules', $this->payload(['account_code' => '1020']))
            ->assertSessionHasErrors('account_code');
    }

    public function test_a_rule_pointing_at_an_unknown_account_is_rejected(): void
    {
        $this->actAsOrg()
            ->post('/banking/rules', $this->payload(['account_code' => '9999']))
            ->assertSessionHasErrors('account_code');
    }

    public function test_two_rules_cannot_share_a_name(): void
    {
        $this->actAsOrg()->post('/banking/rules', $this->payload())->assertRedirect();

        $this->actAsOrg()
            ->post('/banking/rules', $this->payload(['match_text' => 'Andere']))
            ->assertSessionHasErrors('name');
    }

    public function test_a_validity_window_must_not_run_backwards(): void
    {
        $this->actAsOrg()
            ->post('/banking/rules', $this->payload([
                'valid_from' => '2026-06-01',
                'valid_until' => '2026-01-01',
            ]))
            ->assertSessionHasErrors('valid_until');
    }

    public function test_a_rule_can_be_edited_and_deleted(): void
    {
        $this->actAsOrg()->post('/banking/rules', $this->payload())->assertRedirect();
        $rule = BankRule::firstOrFail();

        $this->actAsOrg()
            ->put("/banking/rules/{$rule->id}", $this->payload(['account_code' => '6950']))
            ->assertRedirect();

        $this->assertSame('6950', $rule->fresh()->account_code);

        $this->actAsOrg()->delete("/banking/rules/{$rule->id}")->assertRedirect();
        $this->assertSame(0, BankRule::count());
    }

    // ──────────────────────────────────────────────────────────────
    //  Review list
    // ──────────────────────────────────────────────────────────────

    private function pendingApplication(): BankRuleApplication
    {
        BankRule::create([
            'organization_id' => $this->org->id,
            ...$this->payload(),
        ]);

        $transaction = BankTransaction::create([
            'bank_account_id' => $this->bankAccount->id,
            'date' => '2026-01-15',
            'description' => 'Kartenzahlung',
            'amount' => 120.00,
            'type' => BankTransactionType::Debit->value,
            'creditor_name' => 'ANTHROPIC PBC',
            'is_reconciled' => false,
        ]);

        return app(BankRuleEngine::class)->evaluate($transaction);
    }

    public function test_confirming_a_proposal_without_a_payload_records_a_confirmation(): void
    {
        $application = $this->pendingApplication();

        $this->actAsOrg()->post("/banking/rule-review/{$application->id}")->assertRedirect();

        $this->assertSame(BankRuleOutcome::Confirmed, $application->fresh()->outcome);
    }

    public function test_correcting_a_proposal_records_a_correction(): void
    {
        $application = $this->pendingApplication();

        $this->actAsOrg()
            ->post("/banking/rule-review/{$application->id}", ['account_code' => '6950'])
            ->assertRedirect();

        $fresh = $application->fresh();
        $this->assertSame(BankRuleOutcome::Corrected, $fresh->outcome);
        $this->assertSame('6950', $fresh->final_account_code);
    }

    public function test_a_proposal_cannot_be_decided_twice(): void
    {
        $application = $this->pendingApplication();

        $this->actAsOrg()->post("/banking/rule-review/{$application->id}")->assertRedirect();

        $this->actAsOrg()
            ->post("/banking/rule-review/{$application->id}", ['account_code' => '6950'])
            ->assertStatus(409);

        // The first decision stands.
        $this->assertSame(BankRuleOutcome::Confirmed, $application->fresh()->outcome);
    }
}
