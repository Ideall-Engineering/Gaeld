<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Exceptions\InvalidEntryDataException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class JournalVatTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $expense;

    private Account $revenue;

    private VatRate $vatRate;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->bank = $this->createAccount('1020', AccountType::Asset);
        $this->expense = $this->createAccount('6000', AccountType::Expense);
        $this->revenue = $this->createAccount('3000', AccountType::Revenue);
        $this->vatRate = VatRate::factory()->for($this->organization)->create();
        $this->ledger = app(LedgerService::class);
    }

    public function test_input_line_creates_one_vat_entry_with_correct_base(): void
    {
        $entry = $this->ledger->postEntry($this->organization->id, $this->entryWithVat(
            VatEntryType::Input,
            '100.00',
            '8.10',
        ));

        $vatEntry = VatEntry::sole();

        $this->assertSame($entry->id, $vatEntry->journal_entry_id);
        $this->assertSame($this->vatRate->id, $vatEntry->vat_rate_id);
        $this->assertSame('100.00', $vatEntry->base_amount);
        $this->assertSame('8.10', $vatEntry->vat_amount);
        $this->assertSame(VatEntryType::Input, $vatEntry->type);
        $this->assertTrue($entry->lines->first()->load('vatRate')->vatRate->is($this->vatRate));
    }

    public function test_input_investment_line_creates_input_investment_entry(): void
    {
        $this->ledger->postEntry($this->organization->id, $this->entryWithVat(
            VatEntryType::InputInvestment,
            '100.00',
            '8.10',
        ));

        $this->assertSame(VatEntryType::InputInvestment, VatEntry::sole()->type);
    }

    public function test_output_line_creates_output_entry(): void
    {
        $this->ledger->postEntry($this->organization->id, $this->entryWithVat(
            VatEntryType::Output,
            '240.00',
            '19.44',
        ));

        $this->assertSame(VatEntryType::Output, VatEntry::sole()->type);
    }

    public function test_zero_amount_output_line_still_creates_vat_entry(): void
    {
        $this->ledger->postEntry($this->organization->id, $this->entryWithVat(
            VatEntryType::Output,
            '2808.00',
            '0',
        ));

        $vatEntry = VatEntry::sole();

        $this->assertSame('2808.00', $vatEntry->base_amount);
        $this->assertSame('0.00', $vatEntry->vat_amount);
    }

    public function test_acquisition_line_creates_acquisition_and_input_entries(): void
    {
        $this->ledger->postEntry($this->organization->id, $this->entryWithVat(
            VatEntryType::Acquisition,
            '100.00',
            '8.10',
        ));

        $this->assertCount(2, VatEntry::all());
        $this->assertEqualsCanonicalizing(
            [VatEntryType::Acquisition, VatEntryType::Input],
            VatEntry::all()->pluck('type')->all(),
        );
    }

    public function test_vat_figure_is_copied_to_vat_entry(): void
    {
        $this->ledger->postEntry($this->organization->id, $this->entryWithVat(
            VatEntryType::Output,
            '2808.00',
            '0',
            '230',
        ));

        $this->assertSame('230', VatEntry::sole()->figure);
    }

    public function test_draft_creates_vat_entry_only_when_posted(): void
    {
        $draft = $this->ledger->createDraft($this->organization->id, $this->entryWithVat(
            VatEntryType::Input,
            '100.00',
            '8.10',
        ));

        $this->assertFalse($draft->is_posted);
        $this->assertSame(0, VatEntry::count());

        $posted = $this->ledger->postDraft($draft);

        $this->assertTrue($posted->is_posted);
        $this->assertSame(1, VatEntry::count());
    }

    public function test_line_without_vat_type_creates_no_vat_entry(): void
    {
        $this->ledger->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-01-05',
            reference: 'NO-VAT',
            description: 'No VAT',
            lines: [
                new JournalLineData((string) $this->expense->id, '100.00', '0'),
                new JournalLineData((string) $this->bank->id, '0', '100.00'),
            ],
        ));

        $this->assertSame(0, VatEntry::count());
    }

    public function test_vat_type_without_vat_rate_is_rejected(): void
    {
        $this->expectException(InvalidEntryDataException::class);

        $this->ledger->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-01-05',
            reference: 'MISSING-VAT-RATE',
            description: 'Invalid VAT line',
            lines: [
                new JournalLineData(
                    accountId: (string) $this->expense->id,
                    debit: '100.00',
                    credit: '0',
                    vatAmount: '8.10',
                    vatType: VatEntryType::Input->value,
                ),
                new JournalLineData((string) $this->bank->id, '0', '100.00'),
            ],
        ));
    }

    public function test_posted_reversal_creates_linked_counteracting_vat_entry(): void
    {
        $original = $this->ledger->postEntry($this->organization->id, $this->entryWithVat(
            VatEntryType::Output,
            '240.00',
            '19.44',
        ));
        $reversal = $this->ledger->reverseEntry($original);

        $this->assertSame(1, VatEntry::count());

        $this->ledger->postDraft($reversal);

        $vatEntries = VatEntry::query()->orderBy('id')->get();
        $this->assertCount(2, $vatEntries);
        $this->assertSame('19.44', $vatEntries[0]->vat_amount);
        $this->assertSame('-19.44', $vatEntries[1]->vat_amount);
        $this->assertSame('-240.00', $vatEntries[1]->base_amount);
        $this->assertSame(0, $vatEntries->filter(
            fn (VatEntry $entry): bool => ! JournalEntry::whereKey($entry->journal_entry_id)->exists(),
        )->count());
    }

    private function createAccount(string $code, AccountType $type): Account
    {
        return Account::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $code,
            'type' => $type->value,
        ]);
    }

    private function entryWithVat(
        VatEntryType $type,
        string $baseAmount,
        string $vatAmount,
        ?string $figure = null,
    ): JournalEntryData {
        $vatLine = new JournalLineData(
            accountId: (string) ($type === VatEntryType::Output ? $this->revenue->id : $this->expense->id),
            debit: $type === VatEntryType::Output ? '0' : $baseAmount,
            credit: $type === VatEntryType::Output ? $baseAmount : '0',
            vatRateId: $this->vatRate->uuid,
            vatAmount: $vatAmount,
            vatType: $type->value,
            vatFigure: $figure,
        );
        $counterLine = new JournalLineData(
            accountId: (string) $this->bank->id,
            debit: $type === VatEntryType::Output ? $baseAmount : '0',
            credit: $type === VatEntryType::Output ? '0' : $baseAmount,
        );

        return new JournalEntryData(
            date: '2026-01-05',
            reference: 'VAT-'.uniqid(),
            description: 'VAT entry',
            lines: [$vatLine, $counterLine],
        );
    }
}
