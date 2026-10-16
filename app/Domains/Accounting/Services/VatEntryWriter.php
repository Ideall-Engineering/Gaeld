<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Support\Money;

/**
 * Derives the `vat_entries` rows of one posted journal line.
 *
 * Shared by posting ({@see LedgerService}) and by amending a posted entry,
 * so the VAT report can never read different figures depending on how an
 * entry reached its current state.
 */
class VatEntryWriter
{
    /** @param array<string, int> $vatRateIds VAT rate UUID => internal id */
    public function writeFor(JournalEntry $journalEntry, TransactionLine $line, array $vatRateIds): void
    {
        if ($line->vat_type === null || $line->vat_rate_id === null) {
            return;
        }

        $vatType = $line->vat_type;
        $baseAmount = Money::isZero((string) $line->debit)
            ? (string) $line->credit
            : (string) $line->debit;
        $vatAmount = (string) ($line->vat_amount ?? '0.00');

        if ($this->isVatReversalLine($line, $vatType)) {
            $baseAmount = Money::negate($baseAmount);
            $vatAmount = Money::negate(Money::absoluteAmount($vatAmount));
        } else {
            $vatAmount = Money::absoluteAmount($vatAmount);
        }

        $entryTypes = $vatType === VatEntryType::Acquisition
            ? [VatEntryType::Acquisition, VatEntryType::Input]
            : [$vatType];

        foreach ($entryTypes as $entryType) {
            VatEntry::create([
                'journal_entry_id' => $journalEntry->id,
                'vat_rate_id' => $vatRateIds[$line->vat_rate_id],
                'base_amount' => $baseAmount,
                'vat_amount' => $vatAmount,
                'type' => $entryType,
                'figure' => $line->vat_figure,
            ]);
        }
    }

    private function isVatReversalLine(TransactionLine $line, VatEntryType $vatType): bool
    {
        $isDebitLine = ! Money::isZero((string) $line->debit);

        return match ($vatType) {
            VatEntryType::Output => $isDebitLine,
            VatEntryType::Input, VatEntryType::InputInvestment, VatEntryType::Acquisition => ! $isDebitLine,
        };
    }
}
