<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Actions\GenerateOpeningBalancesAction;
use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;

/**
 * Audit 2026-10-21: the bank balance the steering starts from, across a
 * year-end. By hand: 10'000 deposited in 2025 is still 10'000 in 2026.
 */
class OpeningBalanceAuditTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase;

    private Organization $organization;

    public function test_the_bank_balance_stays_the_same_after_the_opening_entry(): void
    {
        $organization = $this->organization = Organization::factory()->create(['currency' => 'CHF']);
        $bank = $this->account($organization, '1020', AccountType::Asset);
        $equity = $this->account($organization, '2800', AccountType::Equity);
        $this->account($organization, AccountCode::OPENING_BALANCE, AccountType::Equity);

        $this->postJournalEntry('2025-06-01', [
            $this->journalLine($bank, '10000.00', '0', 'Initial deposit'),
            $this->journalLine($equity, '0', '10000.00', 'Capital'),
        ], 'JE-001');
        app(GenerateOpeningBalancesAction::class)->execute($organization->id, 2025);

        $this->assertSame('10000.00', app(LedgerQueryService::class)->accountBalance($bank->id, null, '2026-06-30'));
    }

    private function account(Organization $organization, string $code, AccountType $type): Account
    {
        return Account::create(['organization_id' => $organization->id, 'code' => $code, 'name' => "Account {$code}", 'type' => $type->value, 'is_active' => true]);
    }
}
