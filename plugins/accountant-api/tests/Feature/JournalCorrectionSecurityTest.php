<?php

namespace Plugins\AccountantApi\Tests\Feature;

use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;
use Tests\Traits\WithAccountantApiModule;

class JournalCorrectionSecurityTest extends SecurityTestCase
{
    use WithAccountantApiModule;

    private string $tokenA;

    private JournalEntry $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAccountantApiModule();

        config(['features.api_access' => true]);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);

        Account::create(['organization_id' => $this->orgA->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->orgA->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);

        $response = $this->withToken($this->tokenA)->postJson('/api/v1/journal-entries', [
            'date' => '2026-03-16',
            'reference' => 'SEC-CORR-1',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1020', 'debit' => '200.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '200.00'],
            ],
        ]);
        $response->assertCreated();
        $this->original = JournalEntry::query()->whereKey($response->json('data.id'))->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->tearDownAccountantApiModule();
        parent::tearDown();
    }

    /**
     * Builds org B's "other side" of the fixture entirely through the
     * domain layer (no second HTTP token). A second `withToken()` call
     * within one test hits a Sanctum/Laravel testing guard-caching quirk
     * that `Auth::forgetGuards()` did not reliably clear in practice — see
     * `SecurityTestCase::createApiToken()`'s docblock. `findOriginalOrFail`/
     * `findCorrectionOrFail` are the same two methods `store()` and
     * `show()`/`post()`/etc. all funnel through, so this still exercises
     * the real org-scoping query, just without the unreliable token switch.
     */
    private function prepareCorrectionForAnotherOrganization(): string
    {
        app(CurrentOrganization::class)->set($this->orgB);

        Account::create(['organization_id' => $this->orgB->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->orgB->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);

        $entry = JournalEntry::create([
            'organization_id' => $this->orgB->id,
            'date' => '2026-03-16',
            'reference' => 'SEC-CORR-B',
            'is_posted' => true,
        ]);
        $entry->lines()->createMany([
            ['account_id' => Account::where(['organization_id' => $this->orgB->id, 'code' => '1020'])->value('id'), 'debit' => '50.00', 'credit' => '0.00'],
            ['account_id' => Account::where(['organization_id' => $this->orgB->id, 'code' => '3000'])->value('id'), 'debit' => '0.00', 'credit' => '50.00'],
        ]);

        $correction = app(PrepareJournalCorrectionAction::class)->execute(
            original: $entry,
            reason: 'Org B correction',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Api,
        );

        app(CurrentOrganization::class)->set($this->orgA);

        return $correction->id;
    }

    public function test_a_correction_belonging_to_another_organization_returns_404_without_disclosure(): void
    {
        $correctionBId = $this->prepareCorrectionForAnotherOrganization();

        $this->withToken($this->tokenA)
            ->getJson("/api/v1/journal-corrections/{$correctionBId}")
            ->assertNotFound();
    }

    public function test_updating_the_replacement_of_another_organizations_correction_returns_404(): void
    {
        $correctionBId = $this->prepareCorrectionForAnotherOrganization();

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'sec-cross-org-update')
            ->putJson("/api/v1/journal-corrections/{$correctionBId}/replacement", [
                'date' => '2026-03-20',
                'lines' => [
                    ['account_code' => '1020', 'debit' => '1.00', 'credit' => '0.00'],
                    ['account_code' => '3000', 'debit' => '0.00', 'credit' => '1.00'],
                ],
            ])
            ->assertNotFound();
    }

    public function test_a_token_without_the_accounting_edit_ability_is_forbidden(): void
    {
        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        $limited = $this->ownerA->createToken('limited', ['accounting.view']);
        $limited->accessToken->update(['organization_id' => $this->orgA->id, 'type' => TokenType::Personal]);
        $this->app['auth']->forgetGuards();

        $this->withToken($limited->plainTextToken)
            ->withHeader('Idempotency-Key', 'sec-limited-scope')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Should be forbidden',
                'correction_date' => '2026-03-20',
            ])
            ->assertForbidden();
    }

    public function test_correcting_an_invoice_linked_entry_is_rejected_as_source_managed(): void
    {
        Invoice::factory()->create([
            'organization_id' => $this->orgA->id,
            'journal_entry_id' => $this->original->id,
        ]);

        $this->withToken($this->tokenA)
            ->withHeader('Idempotency-Key', 'sec-source-managed')
            ->postJson("/api/v1/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Should be rejected',
                'correction_date' => '2026-03-20',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'source_managed_entry');
    }
}
