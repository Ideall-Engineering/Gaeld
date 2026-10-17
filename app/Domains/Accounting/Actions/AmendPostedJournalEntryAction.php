<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\DTOs\JournalEntryChangeContext;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Enums\JournalEntryRevisionKind;
use App\Domains\Accounting\Events\JournalEntryAmended;
use App\Domains\Accounting\Exceptions\BankAmountChangeUnacknowledgedException;
use App\Domains\Accounting\Exceptions\DuplicateReferenceException;
use App\Domains\Accounting\Exceptions\JournalEntryNotEditableException;
use App\Domains\Accounting\Exceptions\StaleJournalEntryRevisionException;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
use App\Domains\Accounting\Services\JournalEntryRevisionRecorder;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\VatEntryWriter;
use App\Support\Money;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Services\LineAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Changes a posted journal entry in place while its period is still open.
 *
 * The entry keeps its id; its previous state goes to
 * `journal_entry_revisions` and its revision number goes up by one. Lines
 * and VAT entries are replaced through their models one by one, so the
 * archive and VAT lock hooks and the activity log see every row.
 */
class AmendPostedJournalEntryAction
{
    public function __construct(
        private LedgerService $ledger,
        private LedgerQueryService $ledgerQuery,
        private JournalEntryEditStateService $editState,
        private JournalEntryRevisionRecorder $revisions,
        private VatEntryWriter $vatEntryWriter,
        private LineAllocationService $allocations,
    ) {}

    /**
     * @throws StaleJournalEntryRevisionException When the entry changed since the caller loaded it
     * @throws JournalEntryNotEditableException When the entry or its new date is locked
     * @throws BankAmountChangeUnacknowledgedException When a bank-linked amount changes unconfirmed
     * @throws DuplicateReferenceException When the new reference is taken or reserved
     */
    public function execute(JournalEntry $entry, JournalEntryData $data, JournalEntryChangeContext $context): JournalEntry
    {
        $result = DB::transaction(function () use ($entry, $data, $context): array {
            $locked = JournalEntry::withoutGlobalScopes()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->revision !== $context->expectedRevision) {
                throw new StaleJournalEntryRevisionException($context->expectedRevision, $locked->revision);
            }

            $this->editState->assertAmendable($locked, $this->normalizeDate($data->date));

            $vatRateIds = $this->ledger->validateDraftLines($locked->organization_id, $data->lines);
            $this->assertReferenceAvailable($locked, $data->reference);
            $this->assertBankAmountChangeAcknowledged($locked, $data, $context);

            $revision = $this->revisions->record($locked, JournalEntryRevisionKind::Updated, $context);

            $allocationsBefore = $this->allocations->captureLines($locked);
            $rebuilt = [];

            VatEntry::query()->where('journal_entry_id', $locked->id)->get()->each->delete();
            TransactionLine::query()->where('journal_entry_id', $locked->id)->get()->each->delete();

            $previousRevision = $locked->revision;
            $locked->update([
                'date' => $data->date,
                'reference' => $data->reference,
                'description' => $data->description,
                'revision' => $previousRevision + 1,
            ]);

            foreach ($data->lines as $line) {
                $transactionLine = TransactionLine::create([
                    'journal_entry_id' => $locked->id,
                    'account_id' => $line->accountId,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'description' => $line->description,
                    'vat_rate_id' => $line->vatRateId,
                    'vat_amount' => $line->vatAmount,
                    'vat_type' => $line->vatType,
                    'vat_figure' => $line->vatFigure,
                ]);

                $this->vatEntryWriter->writeFor($locked, $transactionLine, $vatRateIds);
                $rebuilt[] = [$transactionLine, $line];
            }

            $this->allocations->applyAfterRebuild(
                $locked,
                $allocationsBefore,
                $rebuilt,
                $context->source === JournalCorrectionSource::Api ? AllocationSource::Api : AllocationSource::Manual,
            );

            return [$locked, $previousRevision, $revision->id];
        });

        [$amended, $previousRevision, $revisionId] = $result;

        $this->ledger->flushCache($amended->organization_id);

        JournalEntryAmended::dispatch(
            Str::uuid()->toString(),
            $amended->organization_id,
            $amended->id,
            $previousRevision,
            $amended->revision,
            $revisionId,
        );

        return $amended->fresh(['lines.account']);
    }

    /**
     * True when the change alters what any bank-linked account holds, or
     * moves an existing bank amount to a different date: both make the
     * ledger disagree with the statement until it is reconciled again.
     *
     * @param  JournalLineData[]  $newLines
     */
    public function changesBankAmounts(JournalEntry $entry, array $newLines, ?string $newDate = null): bool
    {
        $before = [];
        foreach (TransactionLine::query()->where('journal_entry_id', $entry->id)->get() as $line) {
            $key = (string) $line->account_id;
            $before[$key] = Money::add($before[$key] ?? '0', Money::subtract((string) $line->debit, (string) $line->credit));
        }

        $after = [];
        foreach ($newLines as $line) {
            $key = $line->accountId;
            $after[$key] = Money::add($after[$key] ?? '0', Money::subtract($line->debit, $line->credit));
        }

        $dateChanged = $newDate !== null && $this->normalizeDate($newDate) !== $entry->date->toDateString();

        $affected = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $accountId) {
            $old = $before[$accountId] ?? '0';
            $new = $after[$accountId] ?? '0';
            if (Money::compare($old, $new) !== 0 || ($dateChanged && ! Money::isZero($old))) {
                $affected[] = $accountId;
            }
        }

        return $this->editState->touchesBankAccount($entry->organization_id, $affected);
    }

    private function assertBankAmountChangeAcknowledged(JournalEntry $entry, JournalEntryData $data, JournalEntryChangeContext $context): void
    {
        if ($context->acknowledgeBankAmountChange) {
            return;
        }

        if ($this->changesBankAmounts($entry, $data->lines, $data->date)) {
            throw new BankAmountChangeUnacknowledgedException;
        }
    }

    /** @throws DuplicateReferenceException */
    private function assertReferenceAvailable(JournalEntry $entry, ?string $reference): void
    {
        if ($reference === null || $reference === $entry->reference) {
            return;
        }

        $taken = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $entry->organization_id)
            ->where('reference', $reference)
            ->whereKeyNot($entry->id)
            ->exists()
            || $this->ledgerQuery->isReservedReference($entry->organization_id, $reference);

        if ($taken) {
            throw new DuplicateReferenceException(
                "A journal entry with reference '{$reference}' already exists in this organization."
            );
        }
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime($date);

        return $timestamp === false ? $date : date('Y-m-d', $timestamp);
    }
}
