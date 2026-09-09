<?php

namespace Tests\Feature\Banking;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Banking\Enums\BankRuleAction;
use App\Domains\Banking\Enums\BankRuleDirection;
use App\Domains\Banking\Enums\BankRuleMatchField;
use App\Domains\Banking\Events\BankStatementImported;
use App\Domains\Banking\Jobs\GenerateBankRuleSuggestionsJob;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankRule;
use App\Domains\Banking\Models\BankRuleApplication;
use App\Domains\Banking\Services\BankImportService;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The step from "a statement was imported" to "there are proposals to review".
 */
class BankRuleImportHookTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private BankAccount $bankAccount;

    private string $camt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Config::set('features.rule_engine', true);

        foreach ([['1020', AccountType::Asset], ['6530', AccountType::Expense]] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->org->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type->value,
            ]);
        }

        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'CHF Konto',
            'iban' => 'CH56 0483 5012 3456 7800 9',
            'currency' => 'CHF',
            'is_active' => true,
        ]);

        $this->camt = (string) file_get_contents(__DIR__.'/../../fixtures/camt053_sample.xml');

        // The fixture pays "GitHub Inc" — a foreign software supplier, so the
        // acquisition-tax treatment is the realistic one to assert against.
        BankRule::create([
            'organization_id' => $this->org->id,
            'name' => 'GitHub',
            'match_text' => 'GitHub',
            'match_field' => BankRuleMatchField::Counterparty->value,
            'direction' => BankRuleDirection::Debit->value,
            'account_code' => '6530',
            'tax_treatment' => ExpenseTaxTreatment::ReverseCharge->value,
            'priority' => 10,
            'action' => BankRuleAction::Suggest->value,
            'is_active' => true,
            'reason' => 'Auslandleistung — Bezugsteuer',
        ]);
    }

    public function test_importing_a_statement_announces_it(): void
    {
        Event::fake([BankStatementImported::class]);

        app(BankImportService::class)->importCamtFile($this->bankAccount, $this->camt, 'camt053.xml');

        Event::assertDispatched(BankStatementImported::class, function (BankStatementImported $event): bool {
            return $event->organizationId === $this->org->id && $event->transactionCount > 0;
        });
    }

    public function test_the_announcement_queues_suggestion_work(): void
    {
        Queue::fake();

        app(BankImportService::class)->importCamtFile($this->bankAccount, $this->camt, 'camt053.xml');

        Queue::assertPushed(GenerateBankRuleSuggestionsJob::class);
    }

    public function test_nothing_is_queued_while_the_rule_engine_is_off(): void
    {
        Config::set('features.rule_engine', false);
        Queue::fake();

        app(BankImportService::class)->importCamtFile($this->bankAccount, $this->camt, 'camt053.xml');

        Queue::assertNotPushed(GenerateBankRuleSuggestionsJob::class);
    }

    public function test_an_import_ends_with_a_proposal_waiting_for_review(): void
    {
        app(BankImportService::class)->importCamtFile($this->bankAccount, $this->camt, 'camt053.xml');

        $application = BankRuleApplication::firstOrFail();

        $this->assertSame('6530', $application->suggested_account_code);
        $this->assertSame(ExpenseTaxTreatment::ReverseCharge, $application->suggested_tax_treatment);
        $this->assertSame('Auslandleistung — Bezugsteuer', $application->reason);
    }

    public function test_importing_the_same_statement_twice_adds_no_second_proposal(): void
    {
        $service = app(BankImportService::class);

        $service->importCamtFile($this->bankAccount, $this->camt, 'camt053.xml');
        $before = BankRuleApplication::count();

        // The importer deduplicates on import_hash, and the engine on the
        // transaction — either alone would be enough, both together mean a
        // re-sent statement cannot double anything.
        $service->importCamtFile($this->bankAccount, $this->camt, 'camt053.xml');

        $this->assertSame($before, BankRuleApplication::count());
    }

    public function test_the_command_picks_up_transactions_imported_before_a_rule_existed(): void
    {
        BankRule::query()->delete();

        app(BankImportService::class)->importCamtFile($this->bankAccount, $this->camt, 'camt053.xml');
        $this->assertSame(0, BankRuleApplication::count());

        BankRule::create([
            'organization_id' => $this->org->id,
            'name' => 'GitHub',
            'match_text' => 'GitHub',
            'match_field' => BankRuleMatchField::Counterparty->value,
            'direction' => BankRuleDirection::Debit->value,
            'account_code' => '6530',
            'tax_treatment' => ExpenseTaxTreatment::None->value,
            'priority' => 10,
            'action' => BankRuleAction::Suggest->value,
            'is_active' => true,
        ]);

        $this->artisan('gaeld:apply-bank-rules', ['--organization' => $this->org->id])
            ->assertSuccessful();

        $this->assertSame(1, BankRuleApplication::count());
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        app(BankImportService::class)->importCamtFile($this->bankAccount, $this->camt, 'camt053.xml');
        BankRuleApplication::query()->delete();

        $this->artisan('gaeld:apply-bank-rules', [
            '--organization' => $this->org->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, BankRuleApplication::count());
    }
}
