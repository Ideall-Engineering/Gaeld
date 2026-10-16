<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Enums\JournalEntryEditState;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Exceptions\JournalEntryNotEditableException;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Banking\Enums\BankTransactionType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Every edit state a posted journal entry can be in, and both directions of
 * moving an entry across a period lock (spec 008, FR-002, FR-007).
 */
class JournalEntryEditStateTest extends TestCase
{
    use BuildsPostedJournalEntries, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
    }

    private function state(JournalEntry $entry): JournalEntryEditState
    {
        return app(JournalEntryEditStateService::class)->stateFor($entry->fresh());
    }

    public function test_a_plain_posted_entry_in_an_open_period_is_open(): void
    {
        $this->assertSame(JournalEntryEditState::Open, $this->state($this->postSimpleEntry()));
    }

    public function test_a_draft_reports_draft(): void
    {
        $draft = app(LedgerService::class)->createDraft($this->organization->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'DRAFT-1',
            description: 'Draft',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '10.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '10.00'),
            ],
        ));

        $this->assertSame(JournalEntryEditState::Draft, $this->state($draft));
    }

    public function test_strict_mode_keeps_posted_entries_final(): void
    {
        $entry = $this->postSimpleEntry();
        $this->organization->update(['posted_entry_edit_mode' => PostedEntryEditMode::Strict]);

        $state = $this->state($entry);

        $this->assertSame(JournalEntryEditState::Strict, $state);
        $this->assertTrue($state->suggestsCorrection());
    }

    public function test_a_settled_vat_quarter_locks_entries_even_without_vat_lines(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-05-10');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');

        $this->assertSame(JournalEntryEditState::VatLocked, $this->state($entry));
    }

    public function test_a_reversed_vat_settlement_unlocks_the_quarter_again(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-05-10');
        $settlement = $this->lockVatQuarter('2026-04-01', '2026-06-30');
        JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-07-05',
            'reference' => 'REV-'.$settlement->reference,
            'description' => 'Settlement reversal',
            'is_posted' => true,
        ]);

        $this->assertSame(JournalEntryEditState::Open, $this->state($entry));
    }

    public function test_a_closed_fiscal_year_locks_its_entries(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-05-10');
        FiscalYear::where('organization_id', $this->organization->id)->update(['status' => FiscalYearStatus::Closed]);

        $this->assertSame(JournalEntryEditState::FiscalYearClosed, $this->state($entry));
    }

    public function test_an_archived_entry_is_archived(): void
    {
        $entry = $this->postSimpleEntry();
        $entry->update(['archived_at' => now()]);

        $this->assertSame(JournalEntryEditState::Archived, $this->state($entry));
    }

    public function test_a_reversed_entry_and_its_reversal_are_correction_bound(): void
    {
        $entry = $this->postSimpleEntry();
        $reversal = app(LedgerService::class)->reverseEntry($entry);
        app(LedgerService::class)->postDraft($reversal);

        $this->assertSame(JournalEntryEditState::CorrectionBound, $this->state($entry));
        $this->assertSame(JournalEntryEditState::CorrectionBound, $this->state($reversal));
    }

    public function test_the_original_of_a_guided_correction_is_correction_bound(): void
    {
        $entry = $this->postSimpleEntry();
        app(PrepareJournalCorrectionAction::class)->execute(
            original: $entry,
            reason: 'Wrong account',
            correctionDate: '2026-08-11',
            source: JournalCorrectionSource::Web,
        );

        $this->assertSame(JournalEntryEditState::CorrectionBound, $this->state($entry));
    }

    public function test_an_entry_owned_by_a_bank_transaction_is_source_managed(): void
    {
        $entry = $this->postSimpleEntry();
        $bankAccount = BankAccount::factory()->for($this->organization)->create(['account_id' => $this->bank->id]);
        BankTransaction::create([
            'bank_account_id' => $bankAccount->id,
            'date' => '2026-08-10',
            'description' => 'Rent',
            'amount' => -100.00,
            'type' => BankTransactionType::Debit,
            'journal_entry_id' => $entry->id,
        ]);

        $this->assertSame(JournalEntryEditState::SourceManaged, $this->state($entry));
    }

    public function test_opening_balances_are_system_generated(): void
    {
        $opening = $this->makeAccount(AccountCode::OPENING_BALANCE, 'Opening', AccountType::Equity);
        $entry = $this->postSimpleEntry(reference: 'MY-OPENING', debit: $this->bank, credit: $opening);
        $prefixed = $this->postSimpleEntry(reference: 'OPENING-2026');

        $this->assertSame(JournalEntryEditState::SystemGenerated, $this->state($entry));
        $this->assertSame(JournalEntryEditState::SystemGenerated, $this->state($prefixed));
    }

    public function test_an_asset_disposal_is_system_generated(): void
    {
        $this->assertSame(JournalEntryEditState::SystemGenerated, $this->state($this->postSimpleEntry(reference: 'DISP-123')));
    }

    public function test_an_entry_reversed_only_by_reference_is_correction_bound(): void
    {
        $entry = $this->postSimpleEntry(reference: 'JE-OLD');
        JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-08-11',
            'reference' => 'REV-JE-OLD',
            'description' => 'Legacy reversal without structured link',
            'is_posted' => true,
        ]);

        $this->assertSame(JournalEntryEditState::CorrectionBound, $this->state($entry));
    }

    public function test_a_lettered_line_locks_the_entry(): void
    {
        $entry = $this->postSimpleEntry();
        TransactionLine::whereKey($entry->lines()->value('id'))->update(['lettrage_key' => 'AA']);

        $this->assertSame(JournalEntryEditState::Lettered, $this->state($entry));
    }

    public function test_moving_an_entry_into_a_settled_quarter_is_refused(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-08-10');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');

        try {
            app(JournalEntryEditStateService::class)->assertAmendable($entry->fresh(), '2026-05-01');
            $this->fail('Expected the move into a settled quarter to be refused.');
        } catch (JournalEntryNotEditableException $exception) {
            $this->assertSame(JournalEntryEditState::VatLocked, $exception->state);
            $this->assertSame('2026-05-01', $exception->targetDate);
        }
    }

    public function test_moving_an_entry_out_of_a_settled_quarter_is_refused(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-05-10');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');

        $this->expectException(JournalEntryNotEditableException::class);
        app(JournalEntryEditStateService::class)->assertAmendable($entry->fresh(), '2026-08-01');
    }

    public function test_moving_an_entry_into_a_closed_fiscal_year_is_refused(): void
    {
        FiscalYear::create([
            'organization_id' => $this->organization->id,
            'name' => 'FY2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => FiscalYearStatus::Closed,
        ]);
        $entry = $this->postSimpleEntry(date: '2026-02-10');

        try {
            app(JournalEntryEditStateService::class)->assertAmendable($entry->fresh(), '2025-12-15');
            $this->fail('Expected the move into a closed fiscal year to be refused.');
        } catch (JournalEntryNotEditableException $exception) {
            $this->assertSame(JournalEntryEditState::FiscalYearClosed, $exception->state);
        }
    }

    public function test_moving_within_open_periods_is_allowed(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-08-10');

        app(JournalEntryEditStateService::class)->assertAmendable($entry->fresh(), '2026-11-30');

        $this->addToAssertionCount(1);
    }
}
