<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Enums\JournalCorrectionStatus;
use App\Domains\Accounting\Events\JournalEntryCorrected;
use App\Domains\Accounting\Exceptions\InvalidEntryDataException;
use App\Domains\Accounting\Exceptions\JournalCorrectionStateConflictException;
use App\Domains\Accounting\Exceptions\JournalEntryAlreadyReversedException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\VatPeriodLockService;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Posts a correction's reversal and replacement drafts together, atomically.
 *
 * If either posting fails, the whole outer transaction rolls back and the
 * correction remains a fully intact draft — never half-posted.
 */
class PostJournalCorrectionAction
{
    public function __construct(
        private LedgerService $ledgerService,
        private VatPeriodLockService $vatPeriodLockService,
    ) {}

    /**
     * @throws DomainException When the correction or its entries do not allow posting
     * @throws \DomainException When the fiscal year is closed or the VAT period is locked
     */
    public function execute(JournalCorrection $correction): JournalCorrection
    {
        $posted = DB::transaction(function () use ($correction): JournalCorrection {
            $lockedCorrection = JournalCorrection::withoutGlobalScopes()
                ->whereKey($correction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedCorrection->isDraft()) {
                throw new JournalCorrectionStateConflictException(
                    "Correction {$lockedCorrection->id} is not in draft status and cannot be posted."
                );
            }

            $original = $this->lockEntry($lockedCorrection->original_journal_entry_id);
            $reversal = $this->lockEntry($lockedCorrection->reversal_journal_entry_id);
            $replacement = $this->lockEntry($lockedCorrection->replacement_journal_entry_id);

            $this->assertNotConcurrentlyReversed($original, $reversal);
            $this->assertActiveAccounts($reversal);
            $this->assertActiveAccounts($replacement);
            $this->vatPeriodLockService->assertPeriodUnlocked(
                $lockedCorrection->organization_id,
                $reversal->date->toDateString(),
                $reversal->date->toDateString(),
            );

            $this->ledgerService->postDraft($reversal);
            $this->ledgerService->postDraft($replacement);

            $lockedCorrection->update([
                'status' => JournalCorrectionStatus::Posted,
                'posted_at' => now(),
            ]);

            return $lockedCorrection->fresh(['original.lines.account', 'reversal.lines.account', 'replacement.lines.account']);
        });

        JournalEntryCorrected::dispatch(
            Str::uuid()->toString(),
            $posted->organization_id,
            $posted->original_journal_entry_id,
            $posted->reversal_journal_entry_id,
            $posted->replacement_journal_entry_id,
        );

        return $posted;
    }

    private function lockEntry(string $id): JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()
            ->whereKey($id)
            ->lockForUpdate()
            ->with('lines.account')
            ->firstOrFail();
    }

    /** @throws JournalEntryAlreadyReversedException */
    private function assertNotConcurrentlyReversed(JournalEntry $original, JournalEntry $reversal): void
    {
        $reversedByAnotherEntry = JournalEntry::withoutGlobalScopes()
            ->where('reversal_of_entry_id', $original->id)
            ->whereKeyNot($reversal->id)
            ->exists();

        if ($reversedByAnotherEntry) {
            throw new JournalEntryAlreadyReversedException(
                "Journal entry {$original->id} was reversed outside this correction in the meantime."
            );
        }
    }

    /** @throws InvalidEntryDataException */
    private function assertActiveAccounts(JournalEntry $entry): void
    {
        $accountIds = $entry->lines->pluck('account_id')->unique();

        $activeCount = Account::withoutGlobalScopes()
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->count();

        if ($activeCount !== $accountIds->count()) {
            throw new InvalidEntryDataException(
                "Journal entry {$entry->id} references an inactive or missing account."
            );
        }
    }
}
