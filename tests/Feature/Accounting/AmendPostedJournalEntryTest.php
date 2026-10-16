<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Actions\AmendPostedJournalEntryAction;
use App\Domains\Accounting\DTOs\JournalEntryChangeContext;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Enums\JournalEntryRevisionKind;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Exceptions\StaleJournalEntryRevisionException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Amending a posted journal entry in place (spec 008, User Story 1).
 */
class AmendPostedJournalEntryTest extends TestCase
{
    use BuildsPostedJournalEntries, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
    }

    public function test_a_posted_entry_is_amended_in_place_and_its_prior_state_is_kept(): void
    {
        $entry = $this->postSimpleEntry();

        $response = $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-08-12',
            'reference' => 'JE-1',
            'description' => 'Office supplies, not rent',
            'revision' => 1,
            'change_reason' => 'Wrong expense account',
            'lines' => $this->webLines($this->otherExpense, $this->bank, '100.00'),
        ]);

        $response->assertRedirect('/accounting/journal-entries');
        $response->assertSessionHas('success');

        $entry->refresh();
        $this->assertTrue($entry->is_posted);
        $this->assertSame(2, $entry->revision);
        $this->assertSame('Office supplies, not rent', $entry->description);
        $this->assertSame('2026-08-12', $entry->date->toDateString());
        $this->assertSame(1, JournalEntry::count(), 'No reversal or replacement entry may appear.');

        $revision = JournalEntryRevision::sole();
        $this->assertSame($entry->id, $revision->journal_entry_id);
        $this->assertSame(1, $revision->revision);
        $this->assertSame(JournalEntryRevisionKind::Updated, $revision->kind);
        $this->assertSame('Wrong expense account', $revision->reason);
        $this->assertSame(JournalCorrectionSource::Web, $revision->source);
        $this->assertSame($this->user->id, $revision->user_id);
        $this->assertSame('Original', $revision->snapshot['entry']['description']);
        $this->assertSame('2026-08-10', substr($revision->snapshot['entry']['date'], 0, 10));
        $this->assertCount(2, $revision->snapshot['lines']);
        $this->assertSame($this->expense->id, (int) $revision->snapshot['lines'][0]['account_id']);
    }

    public function test_balances_reflect_the_amendment_immediately(): void
    {
        $entry = $this->postSimpleEntry();
        $query = app(LedgerQueryService::class);

        // Warm the cache with the old figures first.
        $this->assertSame('100.00', $query->accountBalance($this->expense->id));

        $this->amend($entry, [
            new JournalLineData(accountId: (string) $this->otherExpense->id, debit: '100.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]);

        $this->assertSame('0.00', $query->accountBalance($this->expense->id));
        $this->assertSame('100.00', $query->accountBalance($this->otherExpense->id));
    }

    public function test_vat_entries_are_rebuilt_from_the_new_lines(): void
    {
        $rate = VatRate::factory()->for($this->organization)->create();
        $entry = app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'JE-VAT',
            description: 'With VAT',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', vatRateId: $rate->uuid, vatAmount: '8.10', vatType: VatEntryType::Input->value),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
            ],
        ));
        $this->assertSame('100.00', VatEntry::sole()->base_amount);

        $this->amend($entry, [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '200.00', credit: '0', vatRateId: $rate->uuid, vatAmount: '16.20', vatType: VatEntryType::Input->value),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '200.00'),
        ], acknowledgeBank: true);

        $vat = VatEntry::sole();
        $this->assertSame($entry->id, $vat->journal_entry_id);
        $this->assertSame('200.00', $vat->base_amount);
        $this->assertSame('16.20', $vat->vat_amount);

        $snapshotVat = JournalEntryRevision::sole()->snapshot['vat_entries'];
        $this->assertCount(1, $snapshotVat);
        $this->assertSame('100.00', $snapshotVat[0]['base_amount']);
    }

    public function test_the_web_form_keeps_vat_fields_of_a_line(): void
    {
        $rate = VatRate::factory()->for($this->organization)->create();
        $entry = $this->postSimpleEntry();

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-08-10',
            'reference' => 'JE-1',
            'description' => 'Now with input VAT',
            'revision' => 1,
            'lines' => [
                ['account_id' => $this->expense->id, 'debit' => '100.00', 'credit' => '0', 'vat_type' => 'input', 'vat_rate_id' => $rate->uuid, 'vat_amount' => '7.49'],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '100.00'],
            ],
        ])->assertRedirect('/accounting/journal-entries');

        $this->assertSame('7.49', VatEntry::sole()->vat_amount);
        $this->assertSame($rate->uuid, $entry->fresh()->lines()->where('account_id', $this->expense->id)->value('vat_rate_id'));
    }

    public function test_a_stale_revision_is_refused(): void
    {
        $entry = $this->postSimpleEntry();
        $this->amend($entry, $this->linesFor($this->otherExpense, '100.00'));

        $this->expectException(StaleJournalEntryRevisionException::class);
        $this->amend($entry, $this->linesFor($this->expense, '100.00'), expectedRevision: 1);
    }

    public function test_the_web_reports_a_stale_revision_without_changing_anything(): void
    {
        $entry = $this->postSimpleEntry();
        $this->amend($entry, $this->linesFor($this->otherExpense, '100.00'));

        $this->actAsOrg()->from('/accounting/journal-entries')->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-08-10',
            'reference' => 'JE-1',
            'revision' => 1,
            'lines' => $this->webLines($this->expense, $this->bank, '100.00'),
        ])->assertSessionHas('error');

        $this->assertSame(2, $entry->fresh()->revision);
        $this->assertSame(1, JournalEntryRevision::count());
    }

    public function test_changing_a_bank_amount_needs_confirmation(): void
    {
        BankAccount::factory()->for($this->organization)->create(['account_id' => $this->bank->id]);
        $entry = $this->postSimpleEntry();

        $this->actAsOrg()->from('/accounting/journal-entries')->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-08-10',
            'reference' => 'JE-1',
            'revision' => 1,
            'lines' => $this->webLines($this->expense, $this->bank, '120.00'),
        ])->assertSessionHasErrors('acknowledge_bank_amount_change');
        $this->assertSame(1, $entry->fresh()->revision);

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-08-10',
            'reference' => 'JE-1',
            'revision' => 1,
            'acknowledge_bank_amount_change' => true,
            'lines' => $this->webLines($this->expense, $this->bank, '120.00'),
        ])->assertRedirect('/accounting/journal-entries');
        $this->assertSame(2, $entry->fresh()->revision);
    }

    public function test_changing_only_the_expense_account_of_a_bank_entry_needs_no_confirmation(): void
    {
        BankAccount::factory()->for($this->organization)->create(['account_id' => $this->bank->id]);
        $entry = $this->postSimpleEntry();

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-08-10',
            'reference' => 'JE-1',
            'revision' => 1,
            'lines' => $this->webLines($this->otherExpense, $this->bank, '100.00'),
        ])->assertRedirect('/accounting/journal-entries')->assertSessionHasNoErrors();
    }

    public function test_an_entry_in_a_settled_quarter_cannot_be_amended(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-05-10');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-05-10',
            'reference' => 'JE-1',
            'revision' => 1,
            'lines' => $this->webLines($this->otherExpense, $this->bank, '100.00'),
        ])->assertForbidden();

        $this->assertSame(1, $entry->fresh()->revision);
    }

    public function test_moving_an_entry_into_a_settled_quarter_is_refused_on_the_web(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-08-10');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');

        $this->actAsOrg()->from('/accounting/journal-entries')->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-05-10',
            'reference' => 'JE-1',
            'revision' => 1,
            'lines' => $this->webLines($this->expense, $this->bank, '100.00'),
        ])->assertSessionHas('error');

        $this->assertSame('2026-08-10', $entry->fresh()->date->toDateString());
    }

    public function test_strict_mode_still_refuses_amending(): void
    {
        $this->organization->update(['posted_entry_edit_mode' => PostedEntryEditMode::Strict]);
        $entry = $this->postSimpleEntry();

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}", [
            'date' => '2026-08-10',
            'reference' => 'JE-1',
            'revision' => 1,
            'lines' => $this->webLines($this->otherExpense, $this->bank, '100.00'),
        ])->assertForbidden();
    }

    public function test_a_member_without_the_permission_cannot_amend(): void
    {
        $member = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($member->id, ['role' => 'member']);
        $this->assignOrganizationRole($member, $this->organization, 'member');
        $entry = $this->postSimpleEntry();

        $this->actingAs($member)->withSession(['current_organization_id' => $this->organization->id])
            ->put("/accounting/journal-entries/{$entry->id}", [
                'date' => '2026-08-10',
                'reference' => 'JE-1',
                'revision' => 1,
                'lines' => $this->webLines($this->otherExpense, $this->bank, '100.00'),
            ])->assertForbidden();
    }

    public function test_an_entry_of_another_organization_is_not_reachable(): void
    {
        $other = Organization::factory()->create(['posted_entry_edit_mode' => PostedEntryEditMode::Flexible]);
        $foreign = JournalEntry::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'date' => '2026-08-10',
            'reference' => 'FOREIGN',
            'is_posted' => true,
        ]);

        $this->actAsOrg()->put("/accounting/journal-entries/{$foreign->id}", [
            'date' => '2026-08-10',
            'reference' => 'FOREIGN',
            'revision' => 1,
            'lines' => $this->webLines($this->otherExpense, $this->bank, '100.00'),
        ])->assertNotFound();
    }

    public function test_the_list_tells_which_posted_entries_can_be_amended(): void
    {
        $open = $this->postSimpleEntry(reference: 'JE-OPEN');
        $locked = $this->postSimpleEntry(date: '2026-05-10', reference: 'JE-LOCKED');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');

        $response = $this->actAsOrg()->get('/accounting/journal-entries?sort=reference&direction=asc');

        // Sorted by reference: JE-LOCKED, JE-OPEN, then the settlement.
        $response->assertInertia(fn ($page) => $page
            ->where('entries.data.0.reference', 'JE-LOCKED')
            ->where('entries.data.0.can_amend', false)
            ->where('entries.data.0.edit_state', 'vat_locked')
            ->where('entries.data.0.suggests_correction', true)
            ->where('entries.data.1.reference', 'JE-OPEN')
            ->where('entries.data.1.can_amend', true)
            ->where('entries.data.1.edit_state', 'open')
        );
    }

    /** @return JournalLineData[] */
    private function linesFor(Account $debit, string $amount): array
    {
        return [
            new JournalLineData(accountId: (string) $debit->id, debit: $amount, credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: $amount),
        ];
    }

    /** @param JournalLineData[] $lines */
    private function amend(JournalEntry $entry, array $lines, ?int $expectedRevision = null, bool $acknowledgeBank = false): JournalEntry
    {
        $fresh = $entry->fresh();

        return app(AmendPostedJournalEntryAction::class)->execute($fresh, new JournalEntryData(
            date: $fresh->date->toDateString(),
            reference: $fresh->reference,
            description: $fresh->description,
            lines: $lines,
        ), new JournalEntryChangeContext(
            expectedRevision: $expectedRevision ?? $fresh->revision,
            source: JournalCorrectionSource::Web,
            userId: $this->user->id,
            acknowledgeBankAmountChange: $acknowledgeBank,
        ));
    }
}
