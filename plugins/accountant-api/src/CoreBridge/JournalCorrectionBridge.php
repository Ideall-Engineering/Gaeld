<?php

namespace Plugins\AccountantApi\CoreBridge;

use App\Domains\Accounting\Actions\CancelJournalCorrectionAction;
use App\Domains\Accounting\Actions\PostJournalCorrectionAction;
use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Actions\UpdateJournalDraftAction;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Exceptions\AlreadyPostedException;
use App\Domains\Accounting\Exceptions\DuplicateReferenceException;
use App\Domains\Accounting\Exceptions\FiscalYearClosedException;
use App\Domains\Accounting\Exceptions\InvalidEntryDataException;
use App\Domains\Accounting\Exceptions\UnbalancedEntryException;
use App\Domains\Accounting\Exceptions\VatPeriodLockedException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Api\Exceptions\ApiIdempotencyConflictException;
use App\Domains\Api\Services\JournalEntryApiMapper;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The only class in this module that imports core Accounting/Api classes
 * directly (plan.md "Direkte Zugriffe des Moduls auf Kernimplementierungen
 * werden in CoreBridge/ zentralisiert"). Controllers, Requests, and
 * Resources call only this bridge — see
 * tests/Feature/Plugins/AccountantApiModuleBoundaryTest.php, which enforces
 * that boundary.
 */
final class JournalCorrectionBridge
{
    public function __construct(
        private PrepareJournalCorrectionAction $prepareAction,
        private PostJournalCorrectionAction $postAction,
        private CancelJournalCorrectionAction $cancelAction,
        private UpdateJournalDraftAction $updateDraftAction,
        private JournalEntryApiMapper $mapper,
    ) {}

    /** @throws ModelNotFoundException */
    public function findOriginalOrFail(string $organizationId, string $originalId): JournalEntry
    {
        return JournalEntry::where('organization_id', $organizationId)
            ->with('lines.account')
            ->findOrFail($originalId);
    }

    /** @throws ModelNotFoundException */
    public function findCorrectionOrFail(string $organizationId, string $correctionId): JournalCorrection
    {
        return JournalCorrection::where('organization_id', $organizationId)
            ->with(['original.lines.account', 'reversal.lines.account', 'replacement.lines.account'])
            ->findOrFail($correctionId);
    }

    public function userCanCorrect(User $user, JournalEntry $original): bool
    {
        return $user->can('correct', $original);
    }

    /**
     * @param  array<string, mixed>|null  $replacementPayload  Validated explicit or VAT-shorthand lines, or null to copy the original in full
     */
    public function prepare(
        JournalEntry $original,
        string $reason,
        string $correctionDate,
        ?array $replacementPayload,
        ?int $userId,
        ?int $tokenId,
        ?string $clientOperationId,
        ?string $requestHash,
    ): JournalCorrection {
        // PrepareJournalCorrectionAction always overrides the replacement's
        // date with $correctionDate regardless of what's mapped here (both
        // entries of a correction share one date) — 'date' only needs to be
        // present because JournalEntryApiMapper::toData() requires it.
        $replacementData = $replacementPayload !== null
            ? $this->mapper->toData(['date' => $correctionDate, ...$replacementPayload], $original->organization_id)
            : null;

        return $this->prepareAction->execute(
            original: $original,
            reason: $reason,
            correctionDate: $correctionDate,
            source: JournalCorrectionSource::Api,
            replacement: $replacementData,
            userId: $userId,
            tokenId: $tokenId,
            clientOperationId: $clientOperationId,
            requestHash: $requestHash,
        );
    }

    /**
     * @param  array<string, mixed>  $payload  Validated explicit or VAT-shorthand lines
     */
    public function updateReplacement(JournalCorrection $correction, array $payload): JournalCorrection
    {
        // toData()'s `type` ('api') is irrelevant here: UpdateJournalDraftAction
        // only ever writes date/reference/description/lines, never `type` —
        // the replacement keeps whatever type it was given at prepare time.
        $data = $this->mapper->toData($payload, $correction->organization_id);

        $this->updateDraftAction->execute($correction->replacement, $data);

        return $correction->refresh()->load(['original.lines.account', 'reversal.lines.account', 'replacement.lines.account']);
    }

    public function post(JournalCorrection $correction): JournalCorrection
    {
        return $this->postAction->execute($correction);
    }

    public function cancel(JournalCorrection $correction): void
    {
        $this->cancelAction->execute($correction);
    }

    /**
     * Maps a caught exception to one of plan.md's stable conflict codes,
     * without the controller needing to import any core exception class
     * itself. The five correction-specific exceptions already carry their
     * own `errorCode()` (set when they were introduced in Phase 3); the
     * rest are core `LedgerService`/`VatPeriodLockService` exceptions
     * shared with plain journal-entry posting.
     */
    public function errorCodeFor(\Throwable $exception): string
    {
        if (method_exists($exception, 'errorCode')) {
            /** @var string $code */
            $code = $exception->errorCode();

            return $code;
        }

        return match (true) {
            $exception instanceof ApiIdempotencyConflictException => 'idempotency_conflict',
            $exception instanceof AlreadyPostedException => 'journal_correction_concurrent_transition',
            $exception instanceof DuplicateReferenceException => 'duplicate_reference',
            $exception instanceof FiscalYearClosedException => 'fiscal_year_closed',
            $exception instanceof VatPeriodLockedException => 'vat_period_locked',
            $exception instanceof InvalidEntryDataException => 'invalid_entry_data',
            $exception instanceof UnbalancedEntryException => 'journal_entry_unbalanced',
            default => 'domain_error',
        };
    }

    public function httpStatusFor(string $errorCode): int
    {
        return match ($errorCode) {
            'idempotency_conflict',
            'journal_entry_already_corrected',
            'journal_entry_already_reversed',
            'journal_correction_state_conflict',
            'journal_correction_concurrent_transition',
            'duplicate_reference' => 409,
            default => 422,
        };
    }
}
