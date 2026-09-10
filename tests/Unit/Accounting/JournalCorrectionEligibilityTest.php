<?php

namespace Tests\Unit\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Exceptions\SourceManagedEntryException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalCorrectionEligibilityService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalCorrectionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private JournalCorrectionEligibilityService $eligibility;

    private LedgerService $ledgerService;

    private Organization $organization;

    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->eligibility = app(JournalCorrectionEligibilityService::class);
        $this->ledgerService = app(LedgerService::class);

        $user = User::factory()->create();
        $this->organization = Organization::create(['name' => 'Test Org', 'currency' => 'CHF']);
        $this->organization->users()->attach($user->id, ['role' => 'owner']);

        $this->accounts['bank'] = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value,
        ]);
        $this->accounts['revenue'] = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value,
        ]);
    }

    private function postEntry(?string $type, string $reference): JournalEntry
    {
        return $this->ledgerService->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-03-16',
            reference: $reference,
            description: 'Test entry',
            lines: [
                new JournalLineData(accountId: (string) $this->accounts['bank']->id, debit: '100.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->accounts['revenue']->id, debit: '0', credit: '100.00'),
            ],
            type: $type,
        ));
    }

    public function test_a_manually_created_entry_is_eligible(): void
    {
        $entry = $this->postEntry(null, 'MANUAL-1');

        $this->assertFalse($this->eligibility->isSourceManaged($entry));
        $this->eligibility->assertEligible($entry);
        $this->addToAssertionCount(1);
    }

    public function test_an_api_created_entry_is_eligible(): void
    {
        $entry = $this->postEntry('api', 'API-1');

        $this->assertFalse($this->eligibility->isSourceManaged($entry));
    }

    public function test_a_migration_imported_entry_is_eligible(): void
    {
        $entry = $this->postEntry('migration', 'MIG-1');

        $this->assertFalse($this->eligibility->isSourceManaged($entry));
    }

    public function test_a_vat_settlement_entry_is_rejected(): void
    {
        $entry = $this->postEntry('vat_settlement', 'VAT-1');

        $this->assertTrue($this->eligibility->isSourceManaged($entry));
        $this->expectException(SourceManagedEntryException::class);
        $this->eligibility->assertEligible($entry);
    }

    public function test_a_year_end_closing_entry_is_rejected(): void
    {
        $entry = $this->postEntry('year_end_closing', 'YE-1');

        $this->assertTrue($this->eligibility->isSourceManaged($entry));
    }

    public function test_an_entry_linked_to_an_invoice_is_rejected(): void
    {
        $entry = $this->postEntry(null, 'INV-LINK-1');
        Invoice::factory()->create([
            'organization_id' => $this->organization->id,
            'journal_entry_id' => $entry->id,
        ]);

        $this->assertTrue($this->eligibility->isSourceManaged($entry));
    }

    public function test_an_entry_linked_to_an_expense_is_rejected(): void
    {
        $entry = $this->postEntry(null, 'EXP-LINK-1');
        Expense::factory()->create([
            'organization_id' => $this->organization->id,
            'journal_entry_id' => $entry->id,
        ]);

        $this->assertTrue($this->eligibility->isSourceManaged($entry));
    }
}
