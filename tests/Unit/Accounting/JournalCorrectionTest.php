<?php

namespace Tests\Unit\Accounting;

use App\Domains\Accounting\Actions\CancelJournalCorrectionAction;
use App\Domains\Accounting\Actions\PostJournalCorrectionAction;
use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Actions\UpdateJournalDraftAction;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Exceptions\JournalCorrectionStateConflictException;
use App\Domains\Accounting\Exceptions\JournalEntryAlreadyCorrectedException;
use App\Domains\Accounting\Exceptions\LockedReversalDraftException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;

    private PrepareJournalCorrectionAction $prepare;

    private PostJournalCorrectionAction $post;

    private CancelJournalCorrectionAction $cancel;

    private Organization $organization;

    private array $accounts = [];

    private JournalEntry $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerService = app(LedgerService::class);
        $this->prepare = app(PrepareJournalCorrectionAction::class);
        $this->post = app(PostJournalCorrectionAction::class);
        $this->cancel = app(CancelJournalCorrectionAction::class);

        $user = User::factory()->create();
        $this->organization = Organization::create(['name' => 'Test Org', 'currency' => 'CHF']);
        $this->organization->users()->attach($user->id, ['role' => 'owner']);

        $this->accounts['bank'] = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value,
        ]);
        $this->accounts['revenue'] = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value,
        ]);

        $this->original = $this->ledgerService->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-03-16',
            reference: 'INV-100',
            description: 'Original invoice booking',
            lines: [
                new JournalLineData(accountId: (string) $this->accounts['bank']->id, debit: '500.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->accounts['revenue']->id, debit: '0', credit: '500.00'),
            ],
        ));
    }

    public function test_prepare_creates_a_draft_correction_with_swapped_reversal_and_copied_replacement(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );

        $this->assertTrue($correction->isDraft());
        $correction->load('reversal.lines', 'replacement.lines');

        $this->assertFalse($correction->reversal->is_posted);
        $this->assertFalse($correction->replacement->is_posted);
        $this->assertSame($this->original->id, $correction->reversal->reversal_of_entry_id);

        $reversalBankLine = $correction->reversal->lines->firstWhere('account_id', $this->accounts['bank']->id);
        $this->assertSame('0.00', $reversalBankLine->debit);
        $this->assertSame('500.00', $reversalBankLine->credit);

        $replacementBankLine = $correction->replacement->lines->firstWhere('account_id', $this->accounts['bank']->id);
        $this->assertSame('500.00', $replacementBankLine->debit);
        $this->assertSame('0.00', $replacementBankLine->credit);
    }

    public function test_original_entry_is_never_modified_by_prepare_or_post(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );

        $this->post->execute($correction);

        $this->original->refresh();
        $this->assertSame('INV-100', $this->original->reference);
        $this->assertTrue($this->original->is_posted);
        $this->assertNull($this->original->reversal_of_entry_id);
        $this->assertCount(2, $this->original->lines()->get());
    }

    public function test_replacement_payload_overrides_the_copied_lines(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount, corrected to 600',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Api,
            replacement: new JournalEntryData(
                date: '2026-03-20',
                reference: null,
                description: 'Corrected booking',
                lines: [
                    new JournalLineData(accountId: (string) $this->accounts['bank']->id, debit: '600.00', credit: '0'),
                    new JournalLineData(accountId: (string) $this->accounts['revenue']->id, debit: '0', credit: '600.00'),
                ],
            ),
        );

        $correction->load('replacement.lines');
        $bankLine = $correction->replacement->lines->firstWhere('account_id', $this->accounts['bank']->id);
        $this->assertSame('600.00', $bankLine->debit);
    }

    public function test_a_second_correction_of_the_same_original_is_rejected(): void
    {
        $this->prepare->execute(
            original: $this->original,
            reason: 'First correction',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );

        $this->expectException(JournalEntryAlreadyCorrectedException::class);

        $this->prepare->execute(
            original: $this->original,
            reason: 'Second attempt',
            correctionDate: '2026-03-21',
            source: JournalCorrectionSource::Web,
        );
    }

    public function test_a_further_correction_of_the_replacement_is_allowed(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'First correction',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );
        $posted = $this->post->execute($correction);
        $posted->load('replacement');

        $secondCorrection = $this->prepare->execute(
            original: $posted->replacement,
            reason: 'Second correction, on the replacement',
            correctionDate: '2026-03-22',
            source: JournalCorrectionSource::Web,
        );

        $this->assertTrue($secondCorrection->isDraft());
    }

    public function test_post_posts_both_entries_together_and_sets_the_structured_reversal_link(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );

        $posted = $this->post->execute($correction);

        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($posted->posted_at);

        $posted->load('reversal', 'replacement');
        $this->assertTrue($posted->reversal->is_posted);
        $this->assertTrue($posted->replacement->is_posted);
        $this->assertSame($this->original->id, $posted->reversal->reversal_of_entry_id);
    }

    public function test_post_rolls_back_entirely_when_the_replacement_cannot_be_posted(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );

        // Close the correction date's fiscal year only after both drafts
        // already exist, so posting must fail for both entries at once.
        FiscalYear::create([
            'organization_id' => $this->organization->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Closed,
        ]);

        $this->expectException(\DomainException::class);

        try {
            $this->post->execute($correction);
        } finally {
            $correction->refresh();
            $this->assertTrue($correction->isDraft());
            $correction->load('reversal', 'replacement');
            $this->assertFalse($correction->reversal->is_posted);
            $this->assertFalse($correction->replacement->is_posted);
        }
    }

    public function test_cancel_deletes_both_drafts_and_the_correction(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );
        $reversalId = $correction->reversal_journal_entry_id;
        $replacementId = $correction->replacement_journal_entry_id;

        $this->cancel->execute($correction);

        $this->assertDatabaseMissing('journal_corrections', ['id' => $correction->id]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $reversalId]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $replacementId]);
        $this->assertDatabaseHas('journal_entries', ['id' => $this->original->id]);
    }

    public function test_cancel_of_a_posted_correction_is_rejected(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );
        $posted = $this->post->execute($correction);

        $this->expectException(JournalCorrectionStateConflictException::class);

        $this->cancel->execute($posted);
    }

    public function test_post_of_an_already_posted_correction_is_rejected(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );
        $posted = $this->post->execute($correction);

        $this->expectException(JournalCorrectionStateConflictException::class);

        $this->post->execute($posted);
    }

    public function test_the_locked_reversal_draft_cannot_be_edited_directly(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );
        $correction->load('reversal');

        $this->expectException(LockedReversalDraftException::class);

        app(UpdateJournalDraftAction::class)->execute($correction->reversal, new JournalEntryData(
            date: '2026-03-20',
            reference: null,
            description: 'Attempted tamper',
            lines: [
                new JournalLineData(accountId: (string) $this->accounts['bank']->id, debit: '1.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->accounts['revenue']->id, debit: '0', credit: '1.00'),
            ],
        ));
    }

    public function test_the_replacement_draft_can_be_edited_through_the_shared_action(): void
    {
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Wrong amount',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );
        $correction->load('replacement');

        $updated = app(UpdateJournalDraftAction::class)->execute($correction->replacement, new JournalEntryData(
            date: '2026-03-20',
            reference: null,
            description: 'Corrected to 650',
            lines: [
                new JournalLineData(accountId: (string) $this->accounts['bank']->id, debit: '650.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->accounts['revenue']->id, debit: '0', credit: '650.00'),
            ],
        ));

        $bankLine = $updated->lines()->where('account_id', $this->accounts['bank']->id)->first();
        $this->assertSame('650.00', $bankLine->debit);
    }
}
