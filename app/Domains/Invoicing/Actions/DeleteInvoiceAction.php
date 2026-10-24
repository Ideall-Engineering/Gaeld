<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a draft or cancelled invoice.
 */
class DeleteInvoiceAction
{
    public function execute(Invoice $invoice): void
    {
        if (! $invoice->status->isDeletable()) {
            throw new InvalidInvoiceStateException('Only draft or cancelled invoices can be deleted.');
        }

        DB::transaction(function () use ($invoice) {
            $this->deleteJournalEntries($invoice);
            $invoice->lines()->delete();
            $invoice->delete();
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
