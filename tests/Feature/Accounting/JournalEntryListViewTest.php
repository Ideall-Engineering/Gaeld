<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The journal list shows every entry on a single row and lets the bookkeeper
 * narrow the list down by search, filter and sort — the Banana-style working
 * view, rather than a list that has to be expanded row by row.
 */
class JournalEntryListViewTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $revenue;

    private Account $fees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->bank = $this->account('1020', 'Bank', AccountType::Asset);
        $this->revenue = $this->account('3000', 'Dienstleistungsertrag', AccountType::Revenue);
        $this->fees = $this->account('6940', 'Bankspesen', AccountType::Expense);
    }

    public function test_a_two_line_entry_is_summarised_onto_one_row(): void
    {
        $this->postJournalEntry('2026-03-15', [
            $this->journalLine($this->bank, '1250.00', '0.00', 'Zahlungseingang'),
            $this->journalLine($this->revenue, '0.00', '1250.00', 'Beratung'),
        ], 'BAN-001', 'Verkauf Muster AG');

        $this->getJournal()->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.debit_account.code', '1020')
            ->where('entries.data.0.credit_account.code', '3000')
            ->where('entries.data.0.amount', '1250.00')
            ->where('entries.data.0.line_count', 2)
            ->where('entries.data.0.is_split', false)
            ->where('entries.data.0.line_description', 'Zahlungseingang')
        );
    }

    public function test_a_split_entry_names_only_the_side_that_has_one_account(): void
    {
        $this->postJournalEntry('2026-03-16', [
            $this->journalLine($this->bank, '980.00', '0.00', 'Netto'),
            $this->journalLine($this->fees, '20.00', '0.00', 'Spesen'),
            $this->journalLine($this->revenue, '0.00', '1000.00', 'Ertrag'),
        ], 'BAN-002', 'Sammelbuchung');

        $this->getJournal()->assertInertia(fn ($page) => $page
            ->where('entries.data.0.debit_account', null)
            ->where('entries.data.0.credit_account.code', '3000')
            ->where('entries.data.0.amount', '1000.00')
            ->where('entries.data.0.line_count', 3)
            ->where('entries.data.0.is_split', true)
        );
    }

    public function test_the_summary_carries_the_vat_code_and_cost_centre(): void
    {
        $this->postJournalEntry('2026-03-17', [
            $this->journalLine($this->bank, '107.70', '0.00', 'Bank'),
            $this->journalLine($this->revenue, '0.00', '107.70', 'Ertrag'),
        ], 'BAN-003', 'Mit MWST');

        $vatRate = VatRate::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'Normalsatz',
            'rate' => '7.70',
            'code' => 'V77',
        ]);
        $costCentre = CostCenter::create([
            'organization_id' => $this->organization->id,
            'code' => 'KST-10',
            'name' => 'Verwaltung',
        ]);

        JournalEntry::where('reference', 'BAN-003')->firstOrFail()
            ->lines()->update([
                'vat_rate_id' => $vatRate->uuid,
                'vat_amount' => '3.85',
                'cost_center_id' => $costCentre->id,
            ]);

        $this->getJournal()->assertInertia(fn ($page) => $page
            ->where('entries.data.0.vat_code', 'V77')
            ->where('entries.data.0.vat_amount', '7.70')
            ->where('entries.data.0.cost_center', 'KST-10')
        );
    }

    public function test_search_matches_the_reference(): void
    {
        $this->postTwoEntries();

        $this->getJournal(['search' => 'BAN-002'])->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.reference', 'BAN-002')
        );
    }

    public function test_search_matches_an_account_code_on_a_line(): void
    {
        $this->postTwoEntries();

        $this->getJournal(['search' => '6940'])->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.reference', 'BAN-002')
        );
    }

    public function test_search_matches_an_amount_written_with_swiss_grouping(): void
    {
        $this->postTwoEntries();

        $this->getJournal(['search' => "1'250"])->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.reference', 'BAN-001')
        );
    }

    public function test_an_amount_search_matches_the_figure_outright(): void
    {
        $this->postTwoEntries();

        // A LIKE over amounts would let "125" drag in 1'250.00, so a figure is
        // matched as a figure. The text columns still match on substrings.
        $this->getJournal(['search' => '125'])
            ->assertInertia(fn ($page) => $page->has('entries.data', 0));

        $this->getJournal(['search' => '1250.00'])->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.reference', 'BAN-001')
        );
    }

    public function test_search_that_matches_nothing_returns_an_empty_list(): void
    {
        $this->postTwoEntries();

        $this->getJournal(['search' => 'Gibt es nicht'])
            ->assertInertia(fn ($page) => $page->has('entries.data', 0));
    }

    public function test_the_account_filter_limits_the_list(): void
    {
        $this->postTwoEntries();

        $this->getJournal(['filter' => ['account_id' => $this->fees->id]])
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.reference', 'BAN-002')
            );
    }

    public function test_the_status_filter_separates_drafts_from_posted_entries(): void
    {
        $this->postTwoEntries();

        JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-03-20',
            'reference' => 'DRAFT-1',
            'description' => 'Entwurf',
            'is_posted' => false,
        ])->lines()->createMany([
            ['account_id' => $this->bank->id, 'debit' => '10.00', 'credit' => '0.00'],
            ['account_id' => $this->revenue->id, 'debit' => '0.00', 'credit' => '10.00'],
        ]);

        $this->getJournal(['filter' => ['is_posted' => '0']])
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.reference', 'DRAFT-1')
            );

        $this->getJournal(['filter' => ['is_posted' => '1']])
            ->assertInertia(fn ($page) => $page->has('entries.data', 2));
    }

    public function test_the_list_can_be_sorted_by_reference(): void
    {
        $this->postTwoEntries();

        $this->getJournal(['sort' => 'reference', 'direction' => 'asc'])
            ->assertInertia(fn ($page) => $page
                ->where('entries.data.0.reference', 'BAN-001')
                ->where('query.sort', 'reference')
                ->where('query.direction', 'asc')
            );
    }

    private function account(string $code, string $name, AccountType $type): Account
    {
        return Account::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $name,
            'type' => $type->value,
        ]);
    }

    private function postTwoEntries(): void
    {
        $this->postJournalEntry('2026-03-15', [
            $this->journalLine($this->bank, '1250.00', '0.00', 'Zahlungseingang'),
            $this->journalLine($this->revenue, '0.00', '1250.00', 'Beratung'),
        ], 'BAN-001', 'Verkauf Muster AG');

        $this->postJournalEntry('2026-03-16', [
            $this->journalLine($this->fees, '20.00', '0.00', 'Spesen'),
            $this->journalLine($this->bank, '0.00', '20.00', 'Belastung'),
        ], 'BAN-002', 'Bankspesen Q1');
    }

    /** @param array<string, mixed> $query */
    private function getJournal(array $query = []): TestResponse
    {
        return $this->actAsOrg()
            ->get('/accounting/journal-entries?'.http_build_query($query))
            ->assertStatus(200);
    }
}
