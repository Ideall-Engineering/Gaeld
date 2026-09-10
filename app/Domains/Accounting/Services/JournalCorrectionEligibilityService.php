<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Exceptions\SourceManagedEntryException;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Assets\Models\DepreciationEntry;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Payroll\Models\SalarySlip;

/**
 * Determines whether a journal entry is a valid starting point for the
 * guided correction flow.
 *
 * `journal_entries.type` alone is not a reliable signal of origin (plan.md
 * §"Zulässigkeit und Perioden"), so this service checks the actual foreign
 * keys that other modules use to claim ownership of a journal entry, in
 * addition to the known non-manual `type` values as a defense-in-depth
 * fast path. Entries owned by another module must be corrected through
 * that module's own action instead.
 */
class JournalCorrectionEligibilityService
{
    /**
     * `journal_entries.type` values that are always module-managed, even
     * before checking foreign-key linkage. Kept in sync with the `type`
     * values each module's posting action writes.
     *
     * @var array<int, string>
     */
    private const MANAGED_TYPES = [
        'vat_settlement',
        'year_end_closing',
        'bank_match',
        'social_charges',
    ];

    /**
     * @throws SourceManagedEntryException When the entry is owned by another module's workflow
     */
    public function assertEligible(JournalEntry $entry): void
    {
        if ($this->isSourceManaged($entry)) {
            throw new SourceManagedEntryException(
                "Journal entry {$entry->id} is managed by another module and must be corrected through it."
            );
        }
    }

    public function isSourceManaged(JournalEntry $entry): bool
    {
        if (in_array($entry->type, self::MANAGED_TYPES, true)) {
            return true;
        }

        if ($this->isLockedReversalDraft($entry)) {
            return true;
        }

        return $this->hasKnownModuleLinkage($entry);
    }

    /**
     * True for a correction's reversal entry — a generated artifact that is
     * never an independent transaction and is never itself correctable.
     */
    private function isLockedReversalDraft(JournalEntry $entry): bool
    {
        return JournalCorrection::withoutGlobalScopes()
            ->where('reversal_journal_entry_id', $entry->id)
            ->exists();
    }

    private function hasKnownModuleLinkage(JournalEntry $entry): bool
    {
        return Invoice::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->exists()
            || InvoicePayment::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->exists()
            || Expense::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->exists()
            || BankTransaction::withoutGlobalScopes()
                ->where('journal_entry_id', $entry->id)
                ->orWhere('vat_settlement_journal_entry_id', $entry->id)
                ->exists()
            || DepreciationEntry::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->exists()
            || SalarySlip::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->exists();
    }
}
