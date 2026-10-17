<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\Actions\AmendPostedJournalEntryAction;
use App\Domains\Accounting\Actions\DeletePostedJournalEntryAction;
use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Actions\UpdateJournalDraftAction;
use App\Domains\Accounting\DTOs\JournalEntryChangeContext;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Models\AllocationReview;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Every path that copies or rebuilds journal lines keeps their project
 * allocations (spec 009, User Story 4).
 */
class AllocationCarryOverTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->alpha = $this->makeProject('ALPHA');
        $this->beta = $this->makeProject('BETA');
    }

    public function test_posting_writes_the_allocations_of_each_line(): void
    {
        $entry = $this->postAllocated();

        $this->assertSame([['project' => $this->alpha->id, 'employee' => null, 'share' => '100.0000']], $this->allocationsOf($this->lineOn($entry, $this->expense->id)));
        $this->assertSame([], $this->allocationsOf($this->lineOn($entry, $this->bank->id)));
        $this->assertSame(AllocationSource::Manual, LineAllocation::sole()->source);
    }

    public function test_a_line_without_allocations_writes_nothing(): void
    {
        $this->postSimpleEntry();

        $this->assertSame(0, LineAllocation::count());
    }

    public function test_posting_a_draft_keeps_its_allocations(): void
    {
        $draft = app(LedgerService::class)->createDraft($this->organization->id, $this->entryData([
            new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', allocations: $this->whole($this->alpha)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]));

        $posted = app(LedgerService::class)->postDraft($draft);

        $this->assertSame($this->alpha->id, $this->allocationsOf($this->lineOn($posted, $this->expense->id))[0]['project']);
    }

    public function test_a_reversal_carries_the_allocations_of_the_original(): void
    {
        $entry = $this->postAllocated();

        $reversal = app(LedgerService::class)->reverseEntry($entry);

        $line = $this->lineOn($reversal, $this->expense->id);
        $this->assertSame('100.00', (string) $line->credit);
        $this->assertSame($this->alpha->id, $this->allocationsOf($line)[0]['project']);
        $this->assertSame(AllocationSource::Carried, LineAllocation::query()->where('transaction_line_id', $line->id)->sole()->source);
    }

    public function test_a_reversal_keeps_allocations_to_a_project_closed_since(): void
    {
        $entry = $this->postAllocated();
        $this->alpha->update(['is_active' => false]);

        $reversal = app(LedgerService::class)->reverseEntry($entry);

        $this->assertSame($this->alpha->id, $this->allocationsOf($this->lineOn($reversal, $this->expense->id))[0]['project']);
    }

    public function test_updating_a_draft_uses_the_allocations_it_is_given(): void
    {
        $draft = app(LedgerService::class)->createDraft($this->organization->id, $this->entryData([
            new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', allocations: $this->whole($this->alpha)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]));

        $updated = app(UpdateJournalDraftAction::class)->execute($draft, $this->entryData([
            new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', allocations: [
                new LineAllocationData(projectId: $this->alpha->id, employeeId: null, share: '60'),
                new LineAllocationData(projectId: $this->beta->id, employeeId: null, share: '40'),
            ]),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]));

        $this->assertSame([
            ['project' => $this->alpha->id, 'employee' => null, 'share' => '60.0000'],
            ['project' => $this->beta->id, 'employee' => null, 'share' => '40.0000'],
        ], $this->allocationsOf($this->lineOn($updated, $this->expense->id)));
        $this->assertSame(0, AllocationReview::count());
    }

    public function test_an_explicit_empty_list_clears_the_allocations(): void
    {
        $entry = $this->postAllocated();

        $amended = $this->amend($entry, [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', allocations: []),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]);

        $this->assertSame([], $this->allocationsOf($this->lineOn($amended, $this->expense->id)));
        $this->assertSame(0, AllocationReview::count());
    }

    public function test_amending_without_allocations_carries_an_unchanged_line(): void
    {
        $entry = $this->postAllocated();

        $amended = $this->amend($entry, [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', description: 'New text'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]);

        $this->assertSame($this->alpha->id, $this->allocationsOf($this->lineOn($amended, $this->expense->id))[0]['project']);
        $this->assertSame(0, AllocationReview::count());
    }

    public function test_amending_without_allocations_carries_a_line_whose_amount_changed(): void
    {
        $entry = $this->postAllocated();

        $amended = $this->amend($entry, [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '120.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '120.00'),
        ]);

        $this->assertSame($this->alpha->id, $this->allocationsOf($this->lineOn($amended, $this->expense->id))[0]['project']);
        $this->assertSame(0, AllocationReview::count());
    }

    public function test_a_split_line_without_allocations_is_put_on_the_review_list(): void
    {
        $entry = $this->postAllocated();

        $amended = $this->amend($entry, [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '70.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->expense->id, debit: '30.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]);

        $this->assertSame(0, LineAllocation::count(), 'Nothing may be guessed.');
        $review = AllocationReview::sole();
        $this->assertSame($amended->id, $review->journal_entry_id);
        $this->assertNull($review->resolved_at);
        $this->assertSame($this->alpha->id, $review->previous[0]['allocations'][0]['project_id']);
    }

    public function test_identical_lines_allocated_differently_are_not_guessed(): void
    {
        $entry = app(LedgerService::class)->postEntry($this->organization->id, $this->entryData([
            new JournalLineData(accountId: (string) $this->expense->id, debit: '50.00', credit: '0', allocations: $this->whole($this->alpha)),
            new JournalLineData(accountId: (string) $this->expense->id, debit: '50.00', credit: '0', allocations: $this->whole($this->beta)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]));

        $this->amend($entry, [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '50.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->expense->id, debit: '50.00', credit: '0'),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]);

        $this->assertSame(0, LineAllocation::count());
        $this->assertCount(2, AllocationReview::sole()->previous);
    }

    public function test_the_revision_snapshot_keeps_the_allocations(): void
    {
        $entry = $this->postAllocated();
        $lineId = $this->lineOn($entry, $this->expense->id)->id;

        $this->amend($entry, [
            new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', allocations: $this->whole($this->beta)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]);

        $snapshot = JournalEntryRevision::sole()->snapshot;
        $this->assertSame($this->alpha->id, $snapshot['line_allocations'][$lineId][0]['project_id']);
    }

    public function test_deleting_a_posted_entry_keeps_its_allocations_in_the_revision(): void
    {
        $entry = $this->postAllocated();

        app(DeletePostedJournalEntryAction::class)->execute($entry->fresh(), new JournalEntryChangeContext(
            expectedRevision: 1,
            source: JournalCorrectionSource::Web,
            userId: $this->user->id,
        ));

        $this->assertSame(0, LineAllocation::count());
        $snapshot = JournalEntryRevision::sole()->snapshot;
        $this->assertSame($this->alpha->id, array_values($snapshot['line_allocations'])[0][0]['project_id']);
    }

    public function test_a_guided_correction_copies_the_allocations_to_both_drafts(): void
    {
        $entry = $this->postAllocated();

        $correction = app(PrepareJournalCorrectionAction::class)->execute(
            $entry->fresh(),
            'Wrong project',
            '2026-08-20',
            JournalCorrectionSource::Web,
        );

        $reversalLine = $this->lineOn($correction->reversal, $this->expense->id);
        $replacementLine = $this->lineOn($correction->replacement, $this->expense->id);
        $this->assertSame('100.00', (string) $reversalLine->credit);
        $this->assertSame($this->alpha->id, $this->allocationsOf($reversalLine)[0]['project']);
        $this->assertSame($this->alpha->id, $this->allocationsOf($replacementLine)[0]['project']);
    }

    public function test_a_correction_with_its_own_lines_inherits_where_clear(): void
    {
        $entry = $this->postAllocated();

        $correction = app(PrepareJournalCorrectionAction::class)->execute(
            $entry->fresh(),
            'Amount',
            '2026-08-20',
            JournalCorrectionSource::Api,
            $this->entryData([
                new JournalLineData(accountId: (string) $this->expense->id, debit: '90.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '90.00'),
            ], reference: null),
        );

        $this->assertSame($this->alpha->id, $this->allocationsOf($this->lineOn($correction->replacement, $this->expense->id))[0]['project']);
        $this->assertSame(0, AllocationReview::count());
    }

    public function test_a_correction_with_unclear_lines_flags_the_replacement(): void
    {
        $entry = $this->postAllocated();

        $correction = app(PrepareJournalCorrectionAction::class)->execute(
            $entry->fresh(),
            'Other account',
            '2026-08-20',
            JournalCorrectionSource::Api,
            $this->entryData([
                new JournalLineData(accountId: (string) $this->otherExpense->id, debit: '100.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
            ], reference: null),
        );

        $this->assertSame($correction->replacement_journal_entry_id, AllocationReview::sole()->journal_entry_id);
    }

    private function postAllocated(): JournalEntry
    {
        return app(LedgerService::class)->postEntry($this->organization->id, $this->entryData([
            new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', allocations: $this->whole($this->alpha)),
            new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
        ]));
    }

    /** @param  array<int, JournalLineData>  $lines */
    private function entryData(array $lines, ?string $reference = 'JE-1'): JournalEntryData
    {
        return new JournalEntryData(
            date: '2026-08-10',
            reference: $reference,
            description: 'Original',
            lines: $lines,
        );
    }

    /** @param  array<int, JournalLineData>  $lines */
    private function amend(JournalEntry $entry, array $lines): JournalEntry
    {
        $fresh = $entry->fresh();

        return app(AmendPostedJournalEntryAction::class)->execute($fresh, new JournalEntryData(
            date: $fresh->date->toDateString(),
            reference: $fresh->reference,
            description: $fresh->description,
            lines: $lines,
        ), new JournalEntryChangeContext(
            expectedRevision: $fresh->revision,
            source: JournalCorrectionSource::Web,
            userId: $this->user->id,
            acknowledgeBankAmountChange: true,
        ));
    }
}
