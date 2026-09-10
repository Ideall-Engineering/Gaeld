<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Actions\PostJournalCorrectionAction;
use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Exceptions\FiscalYearClosedException;
use App\Domains\Accounting\Exceptions\VatPeriodLockedException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Period gating for posting a correction: open fiscal year, closed fiscal
 * year, and locked VAT period at the correction date (plan.md
 * §"Zulässigkeit und Perioden" / §"Prüfungen beim Verbuchen").
 */
class JournalCorrectionPeriodTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;

    private PrepareJournalCorrectionAction $prepare;

    private PostJournalCorrectionAction $post;

    private Organization $organization;

    private array $accounts = [];

    private JournalEntry $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerService = app(LedgerService::class);
        $this->prepare = app(PrepareJournalCorrectionAction::class);
        $this->post = app(PostJournalCorrectionAction::class);

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
            reference: 'INV-200',
            description: 'Original booking',
            lines: [
                new JournalLineData(accountId: (string) $this->accounts['bank']->id, debit: '500.00', credit: '0'),
                new JournalLineData(accountId: (string) $this->accounts['revenue']->id, debit: '0', credit: '500.00'),
            ],
        ));
    }

    public function test_posting_in_an_open_fiscal_year_succeeds(): void
    {
        FiscalYear::create([
            'organization_id' => $this->organization->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);

        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Correction in open year',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );

        $posted = $this->post->execute($correction);

        $this->assertTrue($posted->isPosted());
    }

    public function test_posting_into_a_closed_fiscal_year_fails_and_leaves_the_correction_as_a_draft(): void
    {
        FiscalYear::create([
            'organization_id' => $this->organization->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Closed,
        ]);

        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Correction date falls in a closed year',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );

        $this->expectException(FiscalYearClosedException::class);

        try {
            $this->post->execute($correction);
        } finally {
            $correction->refresh();
            $this->assertTrue($correction->isDraft());
        }
    }

    public function test_posting_into_a_locked_vat_period_fails_and_leaves_the_correction_as_a_draft(): void
    {
        FiscalYear::create([
            'organization_id' => $this->organization->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);

        // A posted, un-reversed VAT settlement covering Q1 2026 locks that period.
        JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-04-01',
            'reference' => 'VAT-2026-Q1',
            'description' => 'Q1 VAT settlement',
            'is_posted' => true,
            'type' => 'vat_settlement',
            'vat_period_start' => '2026-01-01',
            'vat_period_end' => '2026-03-31',
        ]);

        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Correction date falls in a locked VAT period',
            correctionDate: '2026-03-20',
            source: JournalCorrectionSource::Web,
        );

        $this->expectException(VatPeriodLockedException::class);

        try {
            $this->post->execute($correction);
        } finally {
            $correction->refresh();
            $this->assertTrue($correction->isDraft());
        }
    }

    public function test_a_correction_can_target_a_later_open_period_when_the_original_period_is_locked(): void
    {
        FiscalYear::create([
            'organization_id' => $this->organization->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);

        JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-04-01',
            'reference' => 'VAT-2026-Q1',
            'description' => 'Q1 VAT settlement',
            'is_posted' => true,
            'type' => 'vat_settlement',
            'vat_period_start' => '2026-01-01',
            'vat_period_end' => '2026-03-31',
        ]);

        // Original entry (2026-03-16) is in the locked Q1 period, but the
        // correction is dated into the still-open Q2 — this must succeed.
        $correction = $this->prepare->execute(
            original: $this->original,
            reason: 'Original period locked, correcting into Q2',
            correctionDate: '2026-04-15',
            source: JournalCorrectionSource::Web,
        );

        $posted = $this->post->execute($correction);

        $this->assertTrue($posted->isPosted());
    }
}
