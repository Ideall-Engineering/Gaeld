<?php

namespace Tests\Traits;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;

/**
 * Fixtures for tests around amending and deleting posted journal entries.
 *
 * Expects `$this->organization` to be set (see WithAuthenticatedOrganization).
 */
trait BuildsPostedJournalEntries
{
    protected Account $bank;

    protected Account $revenue;

    protected Account $expense;

    protected Account $otherExpense;

    protected function setUpPostedEntryFixtures(PostedEntryEditMode $mode = PostedEntryEditMode::Flexible): void
    {
        $this->organization->update(['posted_entry_edit_mode' => $mode]);

        FiscalYear::create([
            'organization_id' => $this->organization->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);

        $this->bank = $this->makeAccount('1020', 'Bank', AccountType::Asset);
        $this->revenue = $this->makeAccount('3000', 'Revenue', AccountType::Revenue);
        $this->expense = $this->makeAccount('6000', 'Rent', AccountType::Expense);
        $this->otherExpense = $this->makeAccount('6500', 'Office', AccountType::Expense);
    }

    protected function makeAccount(string $code, string $name, AccountType $type): Account
    {
        return Account::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $name,
            'type' => $type->value,
            'is_active' => true,
        ]);
    }

    protected function postSimpleEntry(
        string $date = '2026-08-10',
        string $reference = 'JE-1',
        string $amount = '100.00',
        ?Account $debit = null,
        ?Account $credit = null,
    ): JournalEntry {
        $debit ??= $this->expense;
        $credit ??= $this->bank;

        return app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: $date,
            reference: $reference,
            description: 'Original',
            lines: [
                new JournalLineData(accountId: (string) $debit->id, debit: $amount, credit: '0'),
                new JournalLineData(accountId: (string) $credit->id, debit: '0', credit: $amount),
            ],
        ));
    }

    protected function lockVatQuarter(string $from, string $to): JournalEntry
    {
        return JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => $to,
            'reference' => "VAT-SETTLEMENT-{$from}-{$to}",
            'description' => 'VAT settlement',
            'is_posted' => true,
            'type' => 'vat_settlement',
            'vat_period_start' => $from,
            'vat_period_end' => $to,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function webLines(Account $debit, Account $credit, string $amount): array
    {
        return [
            ['account_id' => $debit->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $credit->id, 'debit' => '0', 'credit' => $amount],
        ];
    }
}
