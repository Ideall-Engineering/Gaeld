<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\VatPeriodLockService;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Projects\Services\InvoiceProjectService;
use App\Domains\Projects\Services\LineAllocationService;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles write operations for invoice accounting: posting ledger entries
 * and recording payments. Coordinates with LedgerService and applies
 * Swiss rounding to totals.
 */
class InvoiceAccountingService
{
    public function __construct(
        private LedgerService $ledgerService,
        private LedgerQueryService $ledgerQuery,
        private VatPeriodLockService $vatPeriodLocks,
        private LineAllocationService $allocations,
        private InvoiceProjectService $invoiceProjects,
    ) {}

    /**
     * Post the ledger entry for an invoice.
     *
     * Accounting effect (multi-VAT aware):
     *   Debit  1100 Accounts Receivable  (invoice total incl. VAT)
     *   Credit 3000 Revenue from Services (net amount per VAT group)
     *   Credit 2200 VAT Output Tax        (VAT amount per VAT group)
     *
     * For credit notes, debit/credit are reversed:
     *   Credit 1100 Accounts Receivable
     *   Debit  3000 Revenue from Services
     *   Debit  2200 VAT Output Tax
     *
     * Marks the invoice as Sent.
     */
    public function postToLedger(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $orgId = $invoice->organization_id;
            $invoice->load('lines.vatRate');

            $isCreditNote = $invoice->type === InvoiceType::CreditNote;

            $ar = $this->ledgerQuery->resolveAccount($orgId, AccountCode::ACCOUNTS_RECEIVABLE);
            $revenue = $this->ledgerQuery->resolveAccount($orgId, AccountCode::REVENUE);

            $lines = [];

            // For credit notes, amounts are negative — use absolute values and swap debit/credit
            $invoiceTotal = $isCreditNote
                ? Money::absoluteAmount((string) $invoice->total)
                : (string) $invoice->total;

            // AR line: Debit for invoice, Credit for credit note
            $lines[] = new JournalLineData(
                accountId: (string) $ar->id,
                debit: $isCreditNote ? '0' : $invoiceTotal,
                credit: $isCreditNote ? $invoiceTotal : '0',
                description: 'Accounts Receivable',
            );

            // Group invoice lines by VAT rate to create separate revenue + VAT entries
            $groupedByVat = $invoice->lines->groupBy(fn ($line) => $line->vat_rate_id ?? 'none');

            foreach ($groupedByVat as $vatRateId => $invoiceLines) {
                ['netAmount' => $netAmount, 'vatAmount' => $vatAmount] = $this->calculateGroupTotals($invoiceLines);

                // Revenue line: Credit for invoice, Debit for credit note
                if (Money::isPositive($netAmount)) {
                    $vatLabel = $vatRateId !== 'none' && $invoiceLines->first()->vatRate
                        ? " ({$invoiceLines->first()->vatRate->name})"
                        : '';
                    $lines[] = new JournalLineData(
                        accountId: (string) $revenue->id,
                        debit: $isCreditNote ? $netAmount : '0',
                        credit: $isCreditNote ? '0' : $netAmount,
                        description: "Revenue{$vatLabel}",
                    );
                }

                // VAT line: Credit for invoice, Debit for credit note
                if (Money::isPositive($vatAmount)) {
                    $vatOutputAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::VAT_OUTPUT);
                    $rateName = $invoiceLines->first()->vatRate->name ?? 'VAT';
                    $lines[] = new JournalLineData(
                        accountId: (string) $vatOutputAccount->id,
                        debit: $isCreditNote ? $vatAmount : '0',
                        credit: $isCreditNote ? '0' : $vatAmount,
                        description: "VAT Output — {$rateName}",
                    );
                }
            }

            $docType = $isCreditNote ? 'Credit Note' : 'Invoice';
            $reference = $this->resolveReference($invoice);
            $issueDate = $invoice->issue_date->toDateString();
            $journalEntry = $this->ledgerService->postEntry($orgId, new JournalEntryData(
                // Finalized again after a correction: see correctionDate().
                date: $reference === $invoice->number ? $issueDate : $this->correctionDate($orgId, $issueDate),
                reference: $reference,
                description: "{$docType} {$invoice->number} — ".($invoice->customer->name ?? 'N/A'),
                lines: $lines,
            ));

            // Create VatEntry records for the VAT report
            foreach ($groupedByVat as $vatRateId => $invoiceLines) {
                if ($vatRateId === 'none') {
                    continue;
                }

                ['netAmount' => $netAmount, 'vatAmount' => $vatAmount] = $this->calculateGroupTotals($invoiceLines);

                if (Money::isPositive($vatAmount)) {
                    VatEntry::create([
                        'journal_entry_id' => $journalEntry->id,
                        'vat_rate_id' => $vatRateId,
                        // A credit note takes its VAT off the output tax.
                        'base_amount' => $isCreditNote ? Money::negate($netAmount) : $netAmount,
                        'vat_amount' => $isCreditNote ? Money::negate($vatAmount) : $vatAmount,
                        'type' => VatEntryType::Output,
                    ]);
                }
            }

            $invoice->update([
                'status' => InvoiceStatus::Sent,
                'journal_entry_id' => $journalEntry->id,
            ]);

            // Spec 009: the revenue lines belong to the invoice's project.
            $this->invoiceProjects->allocateEntry($invoice, $journalEntry);

            return $invoice->fresh(['lines', 'customer', 'journalEntry.lines']);
        });
    }

    /**
     * The date a correction of an issued invoice is posted on. While the
     * VAT period and fiscal year of $date are open, the correction stays
     * there and the period shows the corrected invoice only. Once the
     * period is settled (VAT settlement posted) it is left as filed and the
     * correction goes to today.
     */
    public function correctionDate(string $orgId, string $date): string
    {
        if ($this->vatPeriodLocks->isLocked($orgId, $date) || $this->ledgerService->isFiscalYearClosedOn($orgId, $date)) {
            return now()->toDateString();
        }

        return $date;
    }

    /**
     * The invoice number, or a versioned one ({number}-v2, -v3, ...) once an
     * earlier posting of the same invoice was reverted to draft: the
     * reversed entry keeps its reference, and references are unique.
     */
    private function resolveReference(Invoice $invoice): string
    {
        $base = (string) $invoice->number;

        if (! $this->ledgerQuery->isDuplicateReferenceAny($invoice->organization_id, $base)) {
            return $base;
        }

        $version = 2;
        while ($this->ledgerQuery->isDuplicateReferenceAny($invoice->organization_id, "{$base}-v{$version}")) {
            $version++;
        }

        return "{$base}-v{$version}";
    }

    /**
     * @param  iterable<int, InvoiceLine>  $invoiceLines
     * @return array{netAmount: numeric-string, vatAmount: numeric-string}
     */
    private function calculateGroupTotals(iterable $invoiceLines): array
    {
        $netAmount = '0';
        $vatAmount = '0';

        foreach ($invoiceLines as $line) {
            if ($line->type === InvoiceLineType::Text) {
                continue;
            }

            $lineAmount = Money::absoluteAmount((string) $line->amount);
            $lineVatAmount = Money::absoluteAmount((string) ($line->vat_amount ?? '0'));

            if ($line->type === InvoiceLineType::Discount) {
                $netAmount = Money::subtract($netAmount, $lineAmount);
                $vatAmount = Money::subtract($vatAmount, $lineVatAmount);
            } else {
                $netAmount = Money::add($netAmount, $lineAmount);
                $vatAmount = Money::add($vatAmount, $lineVatAmount);
            }
        }

        return [
            'netAmount' => $netAmount,
            'vatAmount' => $vatAmount,
        ];
    }

    /**
     * Record a payment for an invoice with full payment tracking.
     *
     * Creates an InvoicePayment record and posts to ledger:
     *   Debit: Bank Account (1020)
     *   Credit: Accounts Receivable (1100)
     *
     * Supports partial payments. Invoice status is updated to PAID
     * when the full amount has been received.
     *
     * @throws ModelNotFoundException When account code not found
     */
    public function recordPayment(Invoice $invoice, RecordPaymentData $data): InvoicePayment
    {
        $bankAccountCode = $data->bankAccountCode ?? AccountCode::BANK_CASH;

        return DB::transaction(function () use ($invoice, $data, $bankAccountCode) {
            $orgId = $invoice->organization_id;

            $bankAccount = $this->ledgerQuery->resolveAccount($orgId, $bankAccountCode);
            $accountsReceivable = $this->ledgerQuery->resolveAccount($orgId, AccountCode::ACCOUNTS_RECEIVABLE);

            $paymentRef = $data->reference ?? 'PAY-'.$invoice->number.'-'.($invoice->payments()->count() + 1);

            $journalEntry = $this->ledgerService->postEntry($orgId, new JournalEntryData(
                date: $data->paymentDate,
                reference: $paymentRef,
                description: "Payment received for {$invoice->number}",
                lines: [
                    new JournalLineData(accountId: (string) $bankAccount->id, debit: $data->amount, credit: '0', description: 'Bank deposit'),
                    new JournalLineData(accountId: (string) $accountsReceivable->id, debit: '0', credit: $data->amount, description: 'Clear receivable'),
                ],
            ));

            $payment = InvoicePayment::create([
                'organization_id' => $orgId,
                'invoice_id' => $invoice->id,
                'journal_entry_id' => $journalEntry->id,
                'amount' => $data->amount,
                'payment_date' => $data->paymentDate,
                'payment_method' => $data->paymentMethod->value,
                'reference' => $paymentRef,
            ]);

            // Check if invoice is fully paid
            if ($invoice->fresh()->isFullyPaid()) {
                $invoice->update(['status' => InvoiceStatus::Paid]);
            }

            return $payment->load('journalEntry');
        });
    }

    /**
     * Correct a payment date by reversing and reposting its ledger entry.
     */
    public function updatePaymentDate(Invoice $invoice, InvoicePayment $payment, string $paymentDate): InvoicePayment
    {
        if ($payment->invoice_id !== $invoice->id || $payment->organization_id !== $invoice->organization_id) {
            throw new \DomainException('The payment does not belong to this invoice.');
        }

        $payment->loadMissing('journalEntry.lines');

        if ($payment->journalEntry === null) {
            throw new \DomainException('Cannot correct a payment without a journal entry.');
        }

        $oldDate = $payment->payment_date->toDateString();
        if ($oldDate === $paymentDate) {
            return $payment->fresh(['journalEntry']);
        }

        $this->vatPeriodLocks->assertPeriodUnlocked($payment->organization_id, $oldDate, $oldDate);
        $this->vatPeriodLocks->assertPeriodUnlocked($payment->organization_id, $paymentDate, $paymentDate);

        return DB::transaction(function () use ($invoice, $payment, $paymentDate, $oldDate): InvoicePayment {
            $original = $payment->journalEntry;

            if ($original->archived_at !== null) {
                throw new \DomainException('Archived payment entries cannot be corrected.');
            }

            $reversal = $this->ledgerService->reverseEntry(
                $original,
                "Correction of payment date for {$invoice->number}",
                'payment_date_correction',
                $oldDate,
            );
            $this->ledgerService->postDraft($reversal);

            // The repost keeps the projects and people of the lines it copies.
            $allocations = $this->allocations->forLinesOf($original);

            $replacement = $this->ledgerService->postEntry(
                $payment->organization_id,
                new JournalEntryData(
                    date: $paymentDate,
                    reference: 'PAYDATE-'.$payment->id.'-'.Str::lower(Str::replace('-', '', Str::uuid()->toString())),
                    description: "Payment received for {$invoice->number} (date corrected)",
                    lines: $original->lines->sortBy('id')->values()->map(fn ($line, int $index) => new JournalLineData(
                        accountId: (string) $line->account_id,
                        debit: (string) $line->debit,
                        credit: (string) $line->credit,
                        description: $line->description,
                        allocations: ($allocations[$index] ?? []) !== [] ? $allocations[$index] : null,
                    ))->all(),
                ),
            );

            $payment->update([
                'journal_entry_id' => $replacement->id,
                'payment_date' => $paymentDate,
            ]);

            return $payment->fresh(['journalEntry']);
        });
    }
}
