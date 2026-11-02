<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\AcquisitionStatus;
use App\Domains\Projects\Models\Acquisition;
use App\Domains\Projects\Services\AcquisitionReceiptCheckService;
use App\Domains\Projects\Services\AcquisitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * A credit note for something bought shows on the list of acquisitions
 * until the article is marked returned, also for part of it.
 */
class AcquisitionReturnTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $anna;

    private Account $inventory;

    private Account $vat;

    private JournalEntry $receipt;

    private Acquisition $stripes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->anna = Employee::factory()->for($this->organization)->create(['first_name' => 'Anna', 'last_name' => 'A']);
        $this->inventory = $this->makeAccount('1500', 'Equipment', AccountType::Asset);
        $this->vat = $this->makeAccount('1170', 'Input VAT', AccountType::Asset);

        $this->receipt = $this->book('2026-10-05', 'BAN-20261005-1141', 'Thomann 92452915: absorbers', [
            $this->line($this->inventory, '1351.53', '0'),
            $this->line($this->vat, '109.47', '0'),
            $this->line($this->bank, '0', '1461.00'),
        ]);
        $this->stripes = $this->buy('Stripe Absorber', '6', '163.00');
        $this->buy('L5 Tile', '2', '164.00');
        $this->buy('Bass Trap', '1', '155.00');
    }

    public function test_the_list_matches_its_receipt(): void
    {
        $this->assertSame([], $this->mismatches());
    }

    public function test_a_credit_note_naming_the_receipt_is_found(): void
    {
        $this->credit('BAN-20261021-1168', 'Gutschrift 92818806: Ruecksendung 2 Stk Stripe Absorber aus Beleg 1141', '301.57', '24.43');

        $found = $this->mismatches();

        $this->assertCount(1, $found);
        $this->assertSame('BAN-20261005-1141', $found[0]['reference']);
        $this->assertSame('1351.53', $found[0]['booked']);
        $this->assertSame('301.57', $found[0]['credited']);
        $this->assertSame('301.57', $found[0]['difference']);
        $this->assertSame(['BAN-20261021-1168'], array_column($found[0]['credits'], 'reference'));
        $this->assertCount(3, $found[0]['articles']);
    }

    public function test_a_credit_note_of_the_same_supplier_is_found(): void
    {
        $this->credit('BAN-20261021-1171', 'Thomann Gutschrift 92818828', '303.42', '24.58');

        $this->assertSame('303.42', $this->mismatches()[0]['difference']);
    }

    public function test_a_write_off_or_a_payment_of_another_supplier_is_no_return(): void
    {
        $writeOff = $this->makeAccount('6800', 'Write-offs', AccountType::Expense);
        $this->book('2026-12-31', 'ABS-1', 'Write-off', [$this->line($writeOff, '1350.53', '0'), $this->line($this->inventory, '0', '1350.53')]);
        $this->credit('BAN-20261022-1180', 'Sale of an old desk to Muster', '100.00', '0.00');

        $this->assertSame([], $this->mismatches());
    }

    public function test_returning_part_of_an_article_splits_it(): void
    {
        $this->credit('BAN-20261021-1168', 'Gutschrift aus Beleg 1141', '301.57', '24.43');

        $returned = app(AcquisitionService::class)->returnPart($this->stripes, '2', 'Gutschrift BAN-20261021-1168');

        $kept = $this->stripes->fresh();
        $this->assertSame('4.00', $kept->quantity);
        $this->assertSame(AcquisitionStatus::Delivered, $kept->status);
        $this->assertSame('2.00', $returned->quantity);
        $this->assertSame(AcquisitionStatus::Returned, $returned->status);
        $this->assertSame('904.72', bcadd((string) $kept->net_amount, (string) $returned->net_amount, 2));
        $this->assertSame([$this->anna->id], $returned->people->pluck('id')->all());
        $this->assertSame($this->receipt->id, $returned->journal_entry_id);
        $this->assertStringContainsString('BAN-20261021-1168', (string) $returned->notes);
        $this->assertSame([], $this->mismatches(), 'Returned in part, list and receipt match again.');
    }

    public function test_returning_all_of_it_keeps_one_row(): void
    {
        app(AcquisitionService::class)->returnPart($this->stripes, '6', null);

        $this->assertSame(AcquisitionStatus::Returned, $this->stripes->fresh()->status);
        $this->assertSame(3, Acquisition::query()->count());
    }

    public function test_the_page_shows_the_mismatch_and_records_the_return(): void
    {
        $this->credit('BAN-20261021-1168', 'Gutschrift aus Beleg 1141', '301.57', '24.43');

        $this->actAsOrg()->get('/projects/acquisitions?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('mismatches', 1)->where('mismatches.0.difference', '301.57'));

        $this->actAsOrg()->post("/projects/acquisitions/{$this->stripes->id}/return", ['quantity' => '2', 'note' => 'Gutschrift 92818806'])
            ->assertSessionHasNoErrors();
        $this->actAsOrg()->post("/projects/acquisitions/{$this->stripes->id}/return", ['quantity' => '9'])
            ->assertSessionHasErrors('quantity');

        $this->actAsOrg()->get('/projects/acquisitions?year=2026')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('mismatches', 0));
    }

    /** @return list<array<string, mixed>> */
    private function mismatches(): array
    {
        return app(AcquisitionReceiptCheckService::class)->mismatches($this->organization->id, 2026);
    }

    private function buy(string $item, string $quantity, string $price): Acquisition
    {
        return app(AcquisitionService::class)->save(null, $this->organization->id, [
            'item' => $item, 'supplier' => 'Thomann', 'purchased_on' => '2026-10-05', 'quantity' => $quantity, 'unit_price' => $price,
            'vat_rate' => '8.10', 'status' => 'delivered', 'journal_entry_id' => $this->receipt->id,
        ], [['employee_id' => $this->anna->id, 'share' => '100']]);
    }

    private function credit(string $reference, string $text, string $net, string $vat): JournalEntry
    {
        return $this->book('2026-10-21', $reference, $text, array_values(array_filter([
            $this->line($this->bank, bcadd($net, $vat, 2), '0'),
            $this->line($this->inventory, '0', $net),
            $vat !== '0.00' ? $this->line($this->vat, '0', $vat) : null,
        ])));
    }

    /** @param  list<JournalLineData>  $lines */
    private function book(string $date, string $reference, string $text, array $lines): JournalEntry
    {
        return app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData($date, $reference, $text, $lines));
    }

    private function line(Account $account, string $debit, string $credit): JournalLineData
    {
        return new JournalLineData(accountId: (string) $account->id, debit: $debit, credit: $credit);
    }
}
