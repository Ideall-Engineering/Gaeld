<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Web (Inertia) coverage for the guided journal correction dialog and the
 * replacement-draft edit view on the journal entry detail page.
 */
class JournalCorrectionWebTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $revenue;

    private JournalEntry $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->bank = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value,
        ]);
        $this->revenue = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value,
        ]);

        $this->actAsOrg()->post('/accounting/journal-entries', [
            'date' => '2026-03-15',
            'reference' => 'JE-CORR-1',
            'description' => 'Original entry',
            'is_posted' => true,
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '300.00', 'credit' => '0'],
                ['account_id' => $this->revenue->id, 'debit' => '0', 'credit' => '300.00'],
            ],
        ]);
        $this->original = JournalEntry::where('reference', 'JE-CORR-1')->firstOrFail();
    }

    public function test_prepare_requires_reason_and_correction_date(): void
    {
        $response = $this->actAsOrg()
            ->from("/accounting/journal-entries/{$this->original->id}")
            ->post("/accounting/journal-entries/{$this->original->id}/corrections", []);

        $response->assertSessionHasErrors(['reason', 'correction_date']);
        $this->assertDatabaseCount('journal_corrections', 0);
    }

    public function test_prepare_creates_a_correction_and_redirects_to_the_replacement(): void
    {
        $response = $this->actAsOrg()->post("/accounting/journal-entries/{$this->original->id}/corrections", [
            'reason' => 'Wrong amount',
            'correction_date' => '2026-03-20',
        ]);

        $correction = JournalCorrection::where('original_journal_entry_id', $this->original->id)->firstOrFail();

        $response->assertRedirect("/accounting/journal-entries/{$correction->replacement_journal_entry_id}");
        $response->assertSessionHas('success');
        $this->assertTrue($correction->isDraft());
        $this->assertSame('Wrong amount', $correction->reason);
    }

    public function test_show_renders_correction_context_for_the_open_replacement_draft(): void
    {
        $this->actAsOrg()->post("/accounting/journal-entries/{$this->original->id}/corrections", [
            'reason' => 'Wrong amount',
            'correction_date' => '2026-03-20',
        ]);
        $correction = JournalCorrection::where('original_journal_entry_id', $this->original->id)->firstOrFail();

        $this->actAsOrg()
            ->get("/accounting/journal-entries/{$correction->replacement_journal_entry_id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/JournalEntryShow')
                ->where('correctionRole', 'replacement')
                ->where('correction.status', 'draft'));
    }

    public function test_update_replacement_changes_its_lines(): void
    {
        $this->actAsOrg()->post("/accounting/journal-entries/{$this->original->id}/corrections", [
            'reason' => 'Wrong amount',
            'correction_date' => '2026-03-20',
        ]);
        $correction = JournalCorrection::where('original_journal_entry_id', $this->original->id)->firstOrFail();

        $response = $this->actAsOrg()->put("/accounting/journal-corrections/{$correction->id}/replacement", [
            'date' => '2026-03-20',
            'reference' => null,
            'description' => 'Corrected to 350',
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '350.00', 'credit' => '0'],
                ['account_id' => $this->revenue->id, 'debit' => '0', 'credit' => '350.00'],
            ],
        ]);

        $response->assertRedirect("/accounting/journal-entries/{$correction->replacement_journal_entry_id}");
        $this->assertDatabaseHas('transaction_lines', [
            'journal_entry_id' => $correction->replacement_journal_entry_id,
            'account_id' => $this->bank->id,
            'debit' => '350.00',
        ]);
    }

    public function test_post_correction_posts_both_entries_together(): void
    {
        $this->actAsOrg()->post("/accounting/journal-entries/{$this->original->id}/corrections", [
            'reason' => 'Wrong amount',
            'correction_date' => '2026-03-20',
        ]);
        $correction = JournalCorrection::where('original_journal_entry_id', $this->original->id)->firstOrFail();

        $response = $this->actAsOrg()->post("/accounting/journal-corrections/{$correction->id}/post");

        $response->assertRedirect('/accounting/journal-entries');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('journal_corrections', ['id' => $correction->id, 'status' => 'posted']);
        $this->assertDatabaseHas('journal_entries', ['id' => $correction->reversal_journal_entry_id, 'is_posted' => true]);
        $this->assertDatabaseHas('journal_entries', ['id' => $correction->replacement_journal_entry_id, 'is_posted' => true]);
    }

    public function test_posting_an_already_posted_correction_fails_gracefully_instead_of_crashing(): void
    {
        $this->actAsOrg()->post("/accounting/journal-entries/{$this->original->id}/corrections", [
            'reason' => 'Wrong amount',
            'correction_date' => '2026-03-20',
        ]);
        $correction = JournalCorrection::where('original_journal_entry_id', $this->original->id)->firstOrFail();
        $this->actAsOrg()->post("/accounting/journal-corrections/{$correction->id}/post");

        $response = $this->actAsOrg()->post("/accounting/journal-corrections/{$correction->id}/post");

        $response->assertSessionHas('error');
    }

    public function test_cancel_correction_deletes_both_drafts_and_keeps_the_original(): void
    {
        $this->actAsOrg()->post("/accounting/journal-entries/{$this->original->id}/corrections", [
            'reason' => 'Wrong amount',
            'correction_date' => '2026-03-20',
        ]);
        $correction = JournalCorrection::where('original_journal_entry_id', $this->original->id)->firstOrFail();
        $reversalId = $correction->reversal_journal_entry_id;
        $replacementId = $correction->replacement_journal_entry_id;

        $response = $this->actAsOrg()->delete("/accounting/journal-corrections/{$correction->id}");

        $response->assertRedirect('/accounting/journal-entries');
        $this->assertDatabaseMissing('journal_corrections', ['id' => $correction->id]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $reversalId]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $replacementId]);
        $this->assertDatabaseHas('journal_entries', ['id' => $this->original->id]);
    }

    public function test_correcting_an_already_corrected_entry_shows_an_error(): void
    {
        $this->actAsOrg()->post("/accounting/journal-entries/{$this->original->id}/corrections", [
            'reason' => 'First correction',
            'correction_date' => '2026-03-20',
        ]);

        $response = $this->actAsOrg()
            ->from("/accounting/journal-entries/{$this->original->id}")
            ->post("/accounting/journal-entries/{$this->original->id}/corrections", [
                'reason' => 'Second attempt',
                'correction_date' => '2026-03-21',
            ]);

        $response->assertSessionHas('error');
        $this->assertSame(1, JournalCorrection::where('original_journal_entry_id', $this->original->id)->count());
    }

    public function test_updating_the_replacement_of_a_posted_correction_is_blocked(): void
    {
        $this->actAsOrg()->post("/accounting/journal-entries/{$this->original->id}/corrections", [
            'reason' => 'Wrong amount',
            'correction_date' => '2026-03-20',
        ]);
        $correction = JournalCorrection::where('original_journal_entry_id', $this->original->id)->firstOrFail();
        $this->actAsOrg()->post("/accounting/journal-corrections/{$correction->id}/post");

        $response = $this->actAsOrg()->put("/accounting/journal-corrections/{$correction->id}/replacement", [
            'date' => '2026-03-20',
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '1.00', 'credit' => '0'],
                ['account_id' => $this->revenue->id, 'debit' => '0', 'credit' => '1.00'],
            ],
        ]);

        $response->assertSessionHas('error');
    }
}
