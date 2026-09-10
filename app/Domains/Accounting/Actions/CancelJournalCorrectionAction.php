<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Exceptions\JournalCorrectionStateConflictException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Services\LedgerService;
use App\Support\Traits\Auditable;
use Illuminate\Support\Facades\DB;

/**
 * Discards an open (draft) correction: deletes both unposted drafts and the
 * correction record itself, atomically. The audit trail (activity log)
 * survives the delete via {@see Auditable}.
 *
 * Only allowed while the correction is still a draft — a posted correction
 * cannot be undone by cancelling; a further correction must be prepared
 * against the replacement instead.
 */
class CancelJournalCorrectionAction
{
    public function __construct(
        private LedgerService $ledgerService,
    ) {}

    /**
     * @throws JournalCorrectionStateConflictException When the correction is already posted
     */
    public function execute(JournalCorrection $correction): void
    {
        DB::transaction(function () use ($correction): void {
            $locked = JournalCorrection::withoutGlobalScopes()
                ->whereKey($correction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isDraft()) {
                throw new JournalCorrectionStateConflictException(
                    "Correction {$locked->id} is already posted and cannot be cancelled."
                );
            }

            // Fetch the drafts before the correction row is gone, but delete
            // the correction first: its foreign keys to both entries use
            // restrictOnDelete, so the entries cannot be removed while it
            // still references them.
            $reversal = $locked->reversal()->withoutGlobalScopes()->firstOrFail();
            $replacement = $locked->replacement()->withoutGlobalScopes()->firstOrFail();

            $locked->delete();

            $this->ledgerService->deleteDraft($reversal);
            $this->ledgerService->deleteDraft($replacement);
        });
    }
}
