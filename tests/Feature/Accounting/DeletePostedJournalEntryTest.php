<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\JournalEntryRevisionKind;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Exceptions\DuplicateReferenceException;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Deleting a posted journal entry in an open period (spec 008, User Story 2).
 */
class DeletePostedJournalEntryTest extends TestCase
{
    use BuildsPostedJournalEntries, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
    }

    public function test_a_posted_entry_is_deleted_and_its_last_state_survives(): void
    {
        $rate = VatRate::factory()->for($this->organization)->create();
        $entry = app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'JE-DUP',
            description: 'Entered twice',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', vatRateId: $rate->uuid, vatAmount: '8.10', vatType: VatEntryType::Input->value),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
            ],
        ));
        $this->assertSame('100.00', app(LedgerQueryService::class)->accountBalance($this->expense->id));

        $response = $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}", [
            'revision' => 1,
            'change_reason' => 'Duplicate of JE-ORIG',
        ]);

        $response->assertRedirect('/accounting/journal-entries');
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
        $this->assertSame(0, TransactionLine::where('journal_entry_id', $entry->id)->count());
        $this->assertSame(0, VatEntry::count());
        $this->assertSame('0.00', app(LedgerQueryService::class)->accountBalance($this->expense->id));

        $revision = JournalEntryRevision::sole();
        $this->assertSame($entry->id, $revision->journal_entry_id);
        $this->assertSame(JournalEntryRevisionKind::Deleted, $revision->kind);
        $this->assertSame('JE-DUP', $revision->reference);
        $this->assertSame('Duplicate of JE-ORIG', $revision->reason);
        $this->assertCount(2, $revision->snapshot['lines']);
        $this->assertCount(1, $revision->snapshot['vat_entries']);
    }

    public function test_the_reference_of_a_deleted_entry_stays_reserved(): void
    {
        $entry = $this->postSimpleEntry(reference: 'JE-GONE');
        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}", ['revision' => 1]);

        $this->expectException(DuplicateReferenceException::class);
        $this->postSimpleEntry(reference: 'JE-GONE');
    }

    public function test_the_web_form_reports_a_reserved_reference(): void
    {
        $entry = $this->postSimpleEntry(reference: 'JE-GONE');
        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}", ['revision' => 1]);

        $this->actAsOrg()->from('/accounting/journal-entries')->post('/accounting/journal-entries', [
            'date' => '2026-08-11',
            'reference' => 'JE-GONE',
            'is_posted' => true,
            'lines' => $this->webLines($this->expense, $this->bank, '50.00'),
        ])->assertSessionHas('error');

        $this->assertSame(0, JournalEntry::where('reference', 'JE-GONE')->count());
    }

    public function test_a_reversed_entry_cannot_be_deleted(): void
    {
        $entry = $this->postSimpleEntry();
        app(LedgerService::class)->postDraft(app(LedgerService::class)->reverseEntry($entry));

        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}", ['revision' => 1])
            ->assertForbidden();
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    public function test_a_lettered_entry_cannot_be_deleted(): void
    {
        $entry = $this->postSimpleEntry();
        TransactionLine::whereKey($entry->lines()->value('id'))->update(['lettrage_key' => 'AB']);

        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}", ['revision' => 1])
            ->assertForbidden();
    }

    public function test_an_entry_in_a_settled_quarter_cannot_be_deleted(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-05-10');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');

        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}", ['revision' => 1])
            ->assertForbidden();
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    public function test_a_stale_revision_is_refused(): void
    {
        $entry = $this->postSimpleEntry();
        $entry->update(['revision' => 2]);

        $this->actAsOrg()->from('/accounting/journal-entries')
            ->delete("/accounting/journal-entries/{$entry->id}", ['revision' => 1])
            ->assertSessionHas('error');
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
        $this->assertSame(0, JournalEntryRevision::count());
    }

    public function test_strict_mode_still_refuses_deleting(): void
    {
        $this->organization->update(['posted_entry_edit_mode' => PostedEntryEditMode::Strict]);
        $entry = $this->postSimpleEntry();

        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}")->assertForbidden();
    }

    public function test_revisions_cannot_be_altered_or_removed(): void
    {
        $entry = $this->postSimpleEntry();
        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}", ['revision' => 1]);
        $revision = JournalEntryRevision::sole();

        try {
            $revision->update(['reason' => 'rewritten']);
            $this->fail('A revision must not be updatable.');
        } catch (\LogicException) {
        }

        $this->expectException(\LogicException::class);
        $revision->delete();
    }
}
