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
 * The link to an entry's original receipt on the entry page: set on a
 * posted entry without a revision, and carried into a correction.
 */
class JournalEntryDocumentLinkTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private const DRIVE = 'https://drive.google.com/file/d/abc123/view';

    private JournalEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $bank = Account::create(['organization_id' => $this->organization->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $revenue = Account::create(['organization_id' => $this->organization->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);

        $this->actAsOrg()->post('/accounting/journal-entries', [
            'date' => '2026-03-15',
            'reference' => 'BAN-20260315-1010',
            'description' => 'Course fee',
            'is_posted' => true,
            'lines' => [
                ['account_id' => $bank->id, 'debit' => '300.00', 'credit' => '0'],
                ['account_id' => $revenue->id, 'debit' => '0', 'credit' => '300.00'],
            ],
        ]);
        $this->entry = JournalEntry::where('reference', 'BAN-20260315-1010')->firstOrFail();
    }

    public function test_a_posted_entry_takes_a_receipt_link_and_shows_it(): void
    {
        $this->actAsOrg()
            ->from("/accounting/journal-entries/{$this->entry->id}")
            ->put("/accounting/journal-entries/{$this->entry->id}/document", ['document_url' => self::DRIVE])
            ->assertRedirect("/accounting/journal-entries/{$this->entry->id}")
            ->assertSessionHas('success');

        $this->entry->refresh();
        $this->assertSame(self::DRIVE, $this->entry->document_url);
        $this->assertSame(1, $this->entry->revision);

        $this->actAsOrg()->get("/accounting/journal-entries/{$this->entry->id}")
            ->assertInertia(fn ($page) => $page
                ->where('entry.document_url', self::DRIVE)
                ->where('can.link_document', true));

        $this->actAsOrg()->put("/accounting/journal-entries/{$this->entry->id}/document", ['document_url' => '']);
        $this->assertNull($this->entry->refresh()->document_url);
    }

    public function test_a_link_must_be_a_web_address(): void
    {
        $this->actAsOrg()
            ->put("/accounting/journal-entries/{$this->entry->id}/document", ['document_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('document_url');

        $this->assertNull($this->entry->refresh()->document_url);
    }

    public function test_a_correction_keeps_the_receipt_link(): void
    {
        $this->entry->update(['document_url' => self::DRIVE]);

        $this->actAsOrg()->post("/accounting/journal-entries/{$this->entry->id}/corrections", [
            'reason' => 'Wrong amount',
            'correction_date' => '2026-03-20',
        ]);

        $correction = JournalCorrection::where('original_journal_entry_id', $this->entry->id)->firstOrFail();
        $this->assertSame(self::DRIVE, JournalEntry::find($correction->replacement_journal_entry_id)->document_url);
        $this->assertSame(self::DRIVE, JournalEntry::find($correction->reversal_journal_entry_id)->document_url);
    }
}
