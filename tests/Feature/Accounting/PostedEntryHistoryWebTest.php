<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Actions\AmendPostedJournalEntryAction;
use App\Domains\Accounting\Actions\PostJournalCorrectionAction;
use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\DTOs\JournalEntryChangeContext;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The views around changed entries and the lighter correction path
 * (spec 008, User Stories 3, 4 and 6).
 */
class PostedEntryHistoryWebTest extends TestCase
{
    use BuildsPostedJournalEntries, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
    }

    public function test_the_detail_page_shows_the_history_and_the_edit_state(): void
    {
        $entry = $this->postSimpleEntry();
        $this->amendTo($entry, $this->otherExpense, 'Wrong account');

        $this->actAsOrg()->get("/accounting/journal-entries/{$entry->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/JournalEntryShow')
                ->where('entry.revision', 2)
                ->where('entry.can_amend', true)
                ->where('entry.edit_state', 'open')
                ->where('postedEntryEditMode', 'flexible')
                ->has('revisions', 1)
                ->where('revisions.0.revision', 1)
                ->where('revisions.0.reason', 'Wrong account')
                ->where('revisions.0.user', $this->user->name)
                ->where('revisions.0.lines.0.account.code', '6000')
                ->has('vatRates')
                ->has('vatTypes', count(VatEntryType::cases()))
            );
    }

    public function test_a_locked_entry_explains_why_and_suggests_the_first_open_date(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-02-10');
        $this->lockVatQuarter('2026-01-01', '2026-03-31');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');

        $this->actAsOrg()->get("/accounting/journal-entries/{$entry->id}")
            ->assertInertia(fn ($page) => $page
                ->where('entry.can_amend', false)
                ->where('entry.edit_state', 'vat_locked')
                ->where('entry.suggests_correction', true)
                ->where('entry.suggested_correction_date', '2026-07-01')
            );
    }

    public function test_an_open_entry_suggests_its_own_date(): void
    {
        $this->assertSame(
            '2026-08-10',
            app(JournalEntryEditStateService::class)->firstOpenDateOnOrAfter($this->organization->id, '2026-08-10'),
        );
    }

    public function test_the_changes_page_lists_amended_and_deleted_entries(): void
    {
        $amended = $this->postSimpleEntry(reference: 'JE-AMENDED');
        $this->amendTo($amended, $this->otherExpense, 'Fix');
        $deleted = $this->postSimpleEntry(reference: 'JE-DELETED');
        $this->actAsOrg()->delete("/accounting/journal-entries/{$deleted->id}", ['revision' => 1]);

        $this->actAsOrg()->get('/accounting/journal-entries/changes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/JournalEntryChanges')
                ->has('revisions.data', 2)
                ->where('revisions.data.0.kind', 'deleted')
                ->where('revisions.data.0.reference', 'JE-DELETED')
                ->where('revisions.data.0.entry_exists', false)
                ->where('revisions.data.1.kind', 'updated')
                ->where('revisions.data.1.entry_exists', true)
            );

        $this->actAsOrg()->get('/accounting/journal-entries')
            ->assertInertia(fn ($page) => $page->where('recentChangesCount', 2));
    }

    public function test_reversal_pairs_and_corrected_originals_can_be_hidden(): void
    {
        $this->organization->update(['posted_entry_edit_mode' => PostedEntryEditMode::Strict]);
        $kept = $this->postSimpleEntry(reference: 'JE-KEPT');
        $reversed = $this->postSimpleEntry(reference: 'JE-REVERSED');
        app(LedgerService::class)->postDraft(app(LedgerService::class)->reverseEntry($reversed));
        $corrected = $this->postSimpleEntry(reference: 'JE-CORRECTED');
        $correction = app(PrepareJournalCorrectionAction::class)->execute(
            original: $corrected,
            reason: 'Wrong account',
            correctionDate: '2026-08-11',
            source: JournalCorrectionSource::Web,
        );
        app(PostJournalCorrectionAction::class)->execute($correction);

        $this->actAsOrg()->get('/accounting/journal-entries?filter[corrections]=hide&sort=reference&direction=asc')
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.reference', 'JE-KEPT')
                ->where('entries.data.1.id', $correction->replacement_journal_entry_id)
            );

        $this->actAsOrg()->get('/accounting/journal-entries')
            ->assertInertia(fn ($page) => $page->has('entries.data', 6));
    }

    public function test_the_owner_switches_the_mode_and_a_member_cannot(): void
    {
        $this->organization->update(['posted_entry_edit_mode' => PostedEntryEditMode::Strict]);

        $this->actAsOrg()->put('/settings/posted-entry-edit-mode', ['posted_entry_edit_mode' => 'flexible'])
            ->assertRedirect('/settings');
        $this->assertSame(PostedEntryEditMode::Flexible, $this->organization->fresh()->posted_entry_edit_mode);

        $this->actAsOrg()->put('/settings/posted-entry-edit-mode', ['posted_entry_edit_mode' => 'anything'])
            ->assertSessionHasErrors('posted_entry_edit_mode');

        $member = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($member->id, ['role' => 'member']);
        $this->assignOrganizationRole($member, $this->organization, 'member');

        $this->actingAs($member)->withSession(['current_organization_id' => $this->organization->id])
            ->put('/settings/posted-entry-edit-mode', ['posted_entry_edit_mode' => 'strict'])
            ->assertForbidden();
        $this->assertSame(PostedEntryEditMode::Flexible, $this->organization->fresh()->posted_entry_edit_mode);
    }

    public function test_editing_a_correction_replacement_keeps_its_vat(): void
    {
        $rate = VatRate::factory()->for($this->organization)->create();
        $original = app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'JE-VAT-CORR',
            description: 'With VAT',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', vatRateId: $rate->uuid, vatAmount: '8.10', vatType: VatEntryType::Input->value),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
            ],
        ));
        $correction = app(PrepareJournalCorrectionAction::class)->execute(
            original: $original,
            reason: 'Wrong account',
            correctionDate: '2026-08-11',
            source: JournalCorrectionSource::Web,
        );

        $this->actAsOrg()->put("/accounting/journal-corrections/{$correction->id}/replacement", [
            'date' => '2026-08-11',
            'reference' => null,
            'description' => 'Office instead of rent',
            'lines' => [
                ['account_id' => $this->otherExpense->id, 'debit' => '100.00', 'credit' => '0', 'vat_type' => 'input', 'vat_rate_id' => $rate->uuid, 'vat_amount' => '8.10'],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '100.00'],
            ],
        ])->assertRedirect();

        $line = JournalEntry::find($correction->replacement_journal_entry_id)->lines()->where('account_id', $this->otherExpense->id)->first();
        $this->assertSame(VatEntryType::Input, $line->vat_type);
        $this->assertSame($rate->uuid, $line->vat_rate_id);
    }

    public function test_a_posted_replacement_can_be_corrected_again(): void
    {
        $this->organization->update(['posted_entry_edit_mode' => PostedEntryEditMode::Strict]);
        $original = $this->postSimpleEntry();
        $first = app(PrepareJournalCorrectionAction::class)->execute(
            original: $original,
            reason: 'First fix',
            correctionDate: '2026-08-11',
            source: JournalCorrectionSource::Web,
        );
        app(PostJournalCorrectionAction::class)->execute($first);
        $replacement = JournalEntry::findOrFail($first->replacement_journal_entry_id);

        $this->actAsOrg()->post("/accounting/journal-entries/{$replacement->id}/corrections", [
            'reason' => 'Second fix',
            'correction_date' => '2026-08-12',
        ])->assertRedirect();

        $this->assertSame(2, JournalCorrection::count());
        $this->assertTrue(JournalCorrection::where('original_journal_entry_id', $replacement->id)->exists());

        $this->actAsOrg()->get("/accounting/journal-entries/{$replacement->id}")
            ->assertInertia(fn ($page) => $page->where('correctionRole', 'original'));
    }

    private function amendTo(JournalEntry $entry, Account $debit, string $reason): void
    {
        $fresh = $entry->fresh();

        app(AmendPostedJournalEntryAction::class)->execute($fresh, new JournalEntryData(
            date: $fresh->date->toDateString(),
            reference: $fresh->reference,
            description: $fresh->description,
            lines: [
                new JournalLineData(accountId: (string) $debit->id, debit: '100.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
            ],
        ), new JournalEntryChangeContext(
            expectedRevision: $fresh->revision,
            source: JournalCorrectionSource::Web,
            userId: $this->user->id,
            reason: $reason,
        ));
    }
}
