<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\JournalEntryEditState;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Sharpening the descriptions of posted entries where amounts, accounts and
 * date are locked.
 */
class AmendJournalEntryTextTest extends TestCase
{
    use BuildsPostedJournalEntries, RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
    }

    public function test_texts_change_in_a_settled_vat_quarter_and_the_old_version_is_kept(): void
    {
        $entry = $this->postSimpleEntry(date: '2026-05-10');
        $this->lockVatQuarter('2026-04-01', '2026-06-30');
        $line = $entry->lines()->orderBy('id')->first();
        $this->assertSame(JournalEntryEditState::VatLocked, app(JournalEntryEditStateService::class)->stateFor($entry));

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}/text", [
            'revision' => 1,
            'description' => '  Miete Mai, Büro Baar  ',
            'lines' => [['id' => $line->id, 'description' => 'Anteil Joël']],
            'change_reason' => 'genauer',
        ])->assertSessionHas('success');

        $entry->refresh();
        $this->assertSame(2, $entry->revision);
        $this->assertSame('Miete Mai, Büro Baar', $entry->description);
        $this->assertSame('Anteil Joël', $line->fresh()->description);
        $this->assertSame('100.00', (string) $line->fresh()->debit);
        $this->assertSame('2026-05-10', $entry->date->toDateString());

        $revision = JournalEntryRevision::sole();
        $this->assertSame('Original', $revision->snapshot['entry']['description']);
        $this->assertSame('genauer', $revision->reason);
    }

    public function test_an_unchanged_text_leaves_no_revision(): void
    {
        $entry = $this->postSimpleEntry();

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}/text", ['revision' => 1, 'description' => 'Original'])
            ->assertSessionHas('success');

        $this->assertSame(1, $entry->fresh()->revision);
        $this->assertSame(0, JournalEntryRevision::count());
    }

    public function test_a_closed_year_the_archive_and_a_stale_revision_refuse(): void
    {
        $closed = $this->postSimpleEntry(date: '2026-05-10', reference: 'JE-CLOSED');
        $archived = $this->postSimpleEntry(reference: 'JE-ARCH');
        $archived->update(['archived_at' => now()]);
        $stale = $this->postSimpleEntry(reference: 'JE-STALE');

        $this->actAsOrg()->put("/accounting/journal-entries/{$stale->id}/text", ['revision' => 5, 'description' => 'x'])
            ->assertSessionHasErrors('revision');
        $this->actAsOrg()->put("/accounting/journal-entries/{$archived->id}/text", ['revision' => 1, 'description' => 'x'])
            ->assertForbidden();

        FiscalYear::where('organization_id', $this->organization->id)->update(['status' => FiscalYearStatus::Closed]);
        $this->actAsOrg()->put("/accounting/journal-entries/{$closed->id}/text", ['revision' => 1, 'description' => 'x'])
            ->assertForbidden();

        $this->assertSame('Original', $stale->fresh()->description);
        $this->assertSame('Original', $closed->fresh()->description);
    }

    public function test_strict_mode_keeps_texts_final_too(): void
    {
        Organization::whereKey($this->organization->id)->update(['posted_entry_edit_mode' => PostedEntryEditMode::Strict]);
        $entry = $this->postSimpleEntry();

        $this->actAsOrg()->put("/accounting/journal-entries/{$entry->id}/text", ['revision' => 1, 'description' => 'x'])
            ->assertForbidden();
    }
}
