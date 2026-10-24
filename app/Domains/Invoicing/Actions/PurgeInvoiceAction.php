<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes a cancelled or soft-deleted invoice and its line items.
 */
class PurgeInvoiceAction
{
    public function execute(Invoice $invoice): void
    {
        if (! $invoice->trashed() && $invoice->status->value !== 'cancelled') {
            throw new InvalidInvoiceStateException('Only cancelled or already deleted invoices can be permanently removed.');
        }

        DB::transaction(function () use ($invoice) {
            $this->deleteJournalEntries($invoice);
            $invoice->lines()->forceDelete();
            $invoice->forceDelete();
        });
    }

    private function deleteJournalEntries(Invoice $invoice): void
    {
        if (! $invoice->journal_entry_id) {
            return;
        }

        // Reversals first, each one points at the entry it reverses. An
        // invoice corrected after issue holds several postings (see
        // Invoice::ledgerEntries()).
        $invoice->ledgerEntries()->where('reference', 'like', 'REV-%')->delete();

        // Then the postings themselves (cascades to transaction_lines & vat_entries)
        $invoice->ledgerEntries()->delete();
        JournalEntry::where('id', $invoice->journal_entry_id)->delete();
    }
}
