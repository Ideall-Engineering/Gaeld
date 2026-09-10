<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Enums\JournalCorrectionStatus;
use App\Domains\Accounting\Exceptions\JournalEntryAlreadyCorrectedException;
use App\Domains\Accounting\Exceptions\JournalEntryAlreadyReversedException;
use App\Domains\Accounting\Exceptions\SourceManagedEntryException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\JournalCorrectionEligibilityService;
use App\Domains\Accounting\Services\LedgerService;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a guided correction: locks the original, and creates a reversal
 * draft (lines swapped, locked) and a replacement draft (editable) as one
 * atomic unit. Neither draft affects the ledger until
 * {@see PostJournalCorrectionAction} posts both together.
 */
class PrepareJournalCorrectionAction
{
    public function __construct(
        private LedgerService $ledgerService,
        private JournalCorrectionEligibilityService $eligibilityService,
    ) {}

    /**
     * @param  JournalEntryData|null  $replacement  Explicit replacement payload; omit to copy the original in full
     *
     * @throws DomainException When the original is not eligible, already corrected, or already reversed
     */
    public function execute(
        JournalEntry $original,
        string $reason,
        string $correctionDate,
        JournalCorrectionSource $source,
        ?JournalEntryData $replacement = null,
        ?int $userId = null,
        ?int $tokenId = null,
        ?string $clientOperationId = null,
        ?string $requestHash = null,
    ): JournalCorrection {
        return DB::transaction(function () use (
            $original, $reason, $correctionDate, $source, $replacement,
            $userId, $tokenId, $clientOperationId, $requestHash,
        ): JournalCorrection {
            $locked = JournalEntry::withoutGlobalScopes()
                ->where('organization_id', $original->organization_id)
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load('lines.account');

            $this->assertCorrectable($locked);
            $this->eligibilityService->assertEligible($locked);

            $reversalData = $this->buildReversalData($locked, $correctionDate);
            $reversalDraft = $this->ledgerService->createDraft($locked->organization_id, $reversalData);
            $reversalDraft->update(['reversal_of_entry_id' => $locked->id]);

            $replacementData = $this->buildReplacementData($locked, $correctionDate, $replacement);
            $replacementDraft = $this->ledgerService->createDraft($locked->organization_id, $replacementData);

            $correction = JournalCorrection::create([
                'organization_id' => $locked->organization_id,
                'original_journal_entry_id' => $locked->id,
                'reversal_journal_entry_id' => $reversalDraft->id,
                'replacement_journal_entry_id' => $replacementDraft->id,
                'status' => JournalCorrectionStatus::Draft,
                'reason' => $reason,
                'source' => $source,
                'user_id' => $userId,
                'token_id' => $tokenId,
                'client_operation_id' => $clientOperationId,
                'request_hash' => $requestHash,
            ]);

            return $correction->load(['original.lines.account', 'reversal.lines.account', 'replacement.lines.account']);
        });
    }

    /**
     * @throws JournalEntryAlreadyCorrectedException
     * @throws JournalEntryAlreadyReversedException
     * @throws SourceManagedEntryException
     */
    private function assertCorrectable(JournalEntry $original): void
    {
        if ($original->archived_at !== null || ! $original->is_posted) {
            throw new SourceManagedEntryException(
                "Journal entry {$original->id} must be a posted, unarchived entry to be corrected."
            );
        }

        $alreadyCorrected = JournalCorrection::withoutGlobalScopes()
            ->where('original_journal_entry_id', $original->id)
            ->exists();

        if ($alreadyCorrected) {
            throw new JournalEntryAlreadyCorrectedException(
                "Journal entry {$original->id} has already been corrected."
            );
        }

        $alreadyReversed = JournalEntry::withoutGlobalScopes()
            ->where('reversal_of_entry_id', $original->id)
            ->exists();

        if ($alreadyReversed) {
            throw new JournalEntryAlreadyReversedException(
                "Journal entry {$original->id} has already been reversed."
            );
        }
    }

    private function buildReversalData(JournalEntry $original, string $correctionDate): JournalEntryData
    {
        $lines = $original->lines->map(fn (TransactionLine $line) => new JournalLineData(
            accountId: (string) $line->account_id,
            debit: (string) $line->credit,
            credit: (string) $line->debit,
            description: $line->description,
            vatRateId: $line->vat_rate_id,
            vatAmount: $line->vat_amount,
            vatType: $line->vat_type?->value,
            vatFigure: $line->vat_figure,
        ))->all();

        return new JournalEntryData(
            date: $correctionDate,
            reference: $original->reference !== null ? 'REV-'.$original->reference : null,
            description: 'Korrektur-Gegenbuchung zu '.($original->reference ?? $original->id),
            lines: $lines,
            type: 'reversal',
        );
    }

    private function buildReplacementData(
        JournalEntry $original,
        string $correctionDate,
        ?JournalEntryData $replacement,
    ): JournalEntryData {
        if ($replacement !== null) {
            return new JournalEntryData(
                date: $correctionDate,
                reference: $replacement->reference,
                description: $replacement->description ?? $original->description,
                lines: $replacement->lines,
                type: $original->type,
            );
        }

        $lines = $original->lines->map(fn (TransactionLine $line) => new JournalLineData(
            accountId: (string) $line->account_id,
            debit: (string) $line->debit,
            credit: (string) $line->credit,
            description: $line->description,
            vatRateId: $line->vat_rate_id,
            vatAmount: $line->vat_amount,
            vatType: $line->vat_type?->value,
            vatFigure: $line->vat_figure,
        ))->all();

        return new JournalEntryData(
            date: $correctionDate,
            reference: null,
            description: $original->description,
            lines: $lines,
            type: $original->type,
        );
    }
}
