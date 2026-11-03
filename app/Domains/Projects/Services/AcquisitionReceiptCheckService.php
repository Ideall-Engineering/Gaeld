<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Projects\Enums\AcquisitionStatus;
use App\Domains\Projects\Models\Acquisition;
use App\Domains\Projects\Models\AcquisitionSkip;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Whether the list of acquisitions still matches its receipts. An article
 * sent back comes as a credit note: money back on the accounts the receipt
 * booked. Such a credit is tied to its receipt when it names the receipt's
 * number, else to the latest receipt of the same supplier before it. A
 * receipt is flagged when its articles not returned are worth more than it
 * books less its credits; a receipt booking more than its articles (other
 * costs on it) is fine.
 */
class AcquisitionReceiptCheckService
{
    /** Rounding of the articles against the receipt. */
    private const TOLERANCE = '0.10';

    /** Money coming back: bank and cash, receivables, payables. */
    private const MONEY_PREFIXES = ['10', '110', '20'];

    /**
     * @return list<array<string, mixed>>
     */
    public function mismatches(string $organizationId, int $year): array
    {
        $articles = Acquisition::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereYear('purchased_on', $year)
            ->whereNotNull('journal_entry_id')
            ->orderBy('id')
            ->get()
            ->groupBy('journal_entry_id');
        if ($articles->isEmpty()) {
            return [];
        }

        $receipts = DB::table('journal_entries')->whereIn('id', $articles->keys())->where('is_posted', true)->get(['id', 'date', 'reference', 'description'])->keyBy('id');
        $lines = $this->linesOf($receipts->keys());

        // What each receipt books, without VAT, and on which accounts.
        $booked = [];
        $accounts = [];
        foreach ($receipts as $id => $receipt) {
            $booked[$id] = '0.00';
            foreach ($lines[$id] ?? [] as $line) {
                if ($line->code !== AccountCode::VAT_INPUT && Money::isPositive((string) $line->debit)) {
                    $booked[$id] = Money::add($booked[$id], (string) $line->debit);
                    $accounts[$id][$line->account_id] = true;
                }
            }
        }

        $credited = [];
        $credits = [];
        foreach ($this->creditsFor($organizationId, $receipts, $accounts) as $credit) {
            $receiptId = $this->receiptOf($credit, $receipts, $accounts, $articles);
            if ($receiptId === null) {
                continue;
            }
            $amount = '0.00';
            foreach ($credit->lines as $line) {
                if (isset($accounts[$receiptId][$line->account_id])) {
                    $amount = Money::add($amount, (string) $line->credit);
                }
            }
            $credited[$receiptId] = Money::add($credited[$receiptId] ?? '0.00', $amount);
            $credits[$receiptId][] = ['id' => $credit->id, 'reference' => $credit->reference, 'date' => $credit->date, 'description' => $credit->description, 'amount' => $amount];
        }

        $found = [];
        foreach ($receipts as $id => $receipt) {
            $kept = $articles[$id]->filter(fn (Acquisition $a) => $a->status !== AcquisitionStatus::Returned);
            $listed = $kept->reduce(fn (string $sum, Acquisition $a) => Money::add($sum, (string) $a->net_amount), '0.00');
            $left = Money::subtract($booked[$id], $credited[$id] ?? '0.00');
            $difference = Money::subtract($listed, $left);
            if (Money::compare($difference, self::TOLERANCE) <= 0) {
                continue;
            }
            $found[] = [
                'journal_entry_id' => $id,
                'reference' => $receipt->reference,
                'date' => substr((string) $receipt->date, 0, 10),
                'supplier' => $articles[$id]->pluck('supplier')->filter()->first(),
                'booked' => $booked[$id],
                'credited' => $credited[$id] ?? '0.00',
                'listed' => $listed,
                'difference' => $difference,
                'credits' => $credits[$id] ?? [],
                'articles' => $kept->values()->map(fn (Acquisition $a) => [
                    'id' => $a->id,
                    'item' => $a->item,
                    'quantity' => (string) $a->quantity,
                    'net_amount' => (string) $a->net_amount,
                ])->all(),
            ];
        }

        return $found;
    }

    /**
     * Purchases of the year nobody has decided on yet: paid or owed, on an
     * asset account (15 to 17) or a cost account (4, 6) that is not put on
     * a job, a project or a person, with no acquisition recorded for them
     * and not set aside as general costs.
     *
     * @return list<array<string, mixed>>
     */
    public function unassigned(string $organizationId, int $year): array
    {
        $entries = DB::table('journal_entries')
            ->where('organization_id', $organizationId)
            ->where('is_posted', true)
            ->whereNull('reversal_of_entry_id')
            ->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])
            ->whereNotExists(fn ($q) => $q->from('acquisitions')->whereColumn('acquisitions.journal_entry_id', 'journal_entries.id'))
            ->whereNotExists(fn ($q) => $q->from('acquisition_skips')->whereColumn('acquisition_skips.journal_entry_id', 'journal_entries.id'))
            ->orderBy('date')
            ->orderBy('reference')
            ->get(['id', 'date', 'reference', 'description', 'document_url']);
        $lines = $this->linesOf($entries->pluck('id'));
        $decided = DB::table('line_allocations')
            ->whereIn('transaction_line_id', $lines->flatten(1)->pluck('id'))
            ->pluck('transaction_line_id')
            ->flip();

        $found = [];
        foreach ($entries as $entry) {
            $entryLines = $lines[$entry->id] ?? collect();
            $paid = $entryLines->contains(fn (\stdClass $l) => Money::isPositive((string) $l->credit) && $this->isMoney($l->code));
            $open = $entryLines->filter(fn (\stdClass $l) => Money::isPositive((string) $l->debit)
                && ($this->isAsset($l->code) || ($this->isCost($l->code) && ! isset($decided[$l->id]))));
            if (! $paid || $open->isEmpty()) {
                continue;
            }
            $net = $open->reduce(fn (string $sum, \stdClass $l) => Money::add($sum, (string) $l->debit), '0.00');
            $vat = $entryLines->filter(fn (\stdClass $l) => $l->code === AccountCode::VAT_INPUT)
                ->reduce(fn (string $sum, \stdClass $l) => Money::add($sum, Money::subtract((string) $l->debit, (string) $l->credit)), '0.00');
            $gross = $entryLines->filter(fn (\stdClass $l) => $this->isMoney($l->code))
                ->reduce(fn (string $sum, \stdClass $l) => Money::add($sum, (string) $l->credit), '0.00');
            $found[] = [
                'journal_entry_id' => $entry->id,
                'date' => substr((string) $entry->date, 0, 10),
                'reference' => $entry->reference,
                'description' => $entry->description,
                'document_url' => $entry->document_url,
                'accounts' => $open->pluck('code')->unique()->values()->all(),
                // The cost lines a job can take; asset lines need an acquisition.
                'cost_lines' => $open->filter(fn (\stdClass $l) => $this->isCost($l->code))->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'net' => $net,
                'gross' => $gross,
                // The rate the VAT on the receipt makes of its net.
                'vat_rate' => Money::isPositive($vat) && Money::isPositive($net) ? bcadd(bcadd(bcdiv(bcmul($vat, '100', 6), $net, 6), '0.05', 1), '0', 2) : null,
            ];
        }

        return $found;
    }

    /**
     * Set purchases aside as no acquisition.
     *
     * @param  list<string>  $entryIds
     */
    public function skip(string $organizationId, array $entryIds): void
    {
        $ids = DB::table('journal_entries')->where('organization_id', $organizationId)->whereIn('id', $entryIds)->pluck('id');
        foreach ($ids as $id) {
            AcquisitionSkip::withoutGlobalScopes()->firstOrCreate(['organization_id' => $organizationId, 'journal_entry_id' => $id]);
        }
    }

    /** Whether a booked entry is a purchase still waiting for a decision. */
    public function isOpen(string $organizationId, string $entryId, int $year): bool
    {
        return in_array($entryId, array_column($this->unassigned($organizationId, $year), 'journal_entry_id'), true);
    }

    private function isMoney(string $code): bool
    {
        return collect(self::MONEY_PREFIXES)->contains(fn (string $prefix) => str_starts_with($code, $prefix));
    }

    private function isAsset(string $code): bool
    {
        return preg_match('/^1[5-7]/', $code) === 1;
    }

    private function isCost(string $code): bool
    {
        return preg_match('/^[46]/', $code) === 1;
    }

    /**
     * Posted entries after a receipt that take an amount off one of its
     * accounts against money coming back: no write-off, no reversal.
     *
     * @param  Collection<string, \stdClass>  $receipts
     * @param  array<string, array<int, true>>  $accounts
     * @return Collection<int, \stdClass>
     */
    private function creditsFor(string $organizationId, Collection $receipts, array $accounts): Collection
    {
        $accountIds = collect($accounts)->flatMap(fn (array $ids) => array_keys($ids))->unique()->values();
        if ($accountIds->isEmpty()) {
            return collect();
        }

        $entries = DB::table('journal_entries')
            ->where('organization_id', $organizationId)
            ->where('is_posted', true)
            ->whereNull('reversal_of_entry_id')
            ->where('date', '>=', $receipts->min('date'))
            ->whereNotIn('id', $receipts->keys())
            ->whereExists(fn ($q) => $q->from('transaction_lines')
                ->whereColumn('transaction_lines.journal_entry_id', 'journal_entries.id')
                ->whereIn('transaction_lines.account_id', $accountIds)
                ->where('transaction_lines.credit', '>', 0))
            ->get(['id', 'date', 'reference', 'description']);
        $lines = $this->linesOf($entries->pluck('id'));

        return $entries->map(function (\stdClass $entry) use ($lines) {
            $entry->lines = $lines[$entry->id] ?? collect();
            $entry->date = substr((string) $entry->date, 0, 10);

            return $entry;
        })->filter(fn (\stdClass $entry) => $entry->lines->contains(fn (\stdClass $line) => Money::isPositive((string) $line->debit)
            && collect(self::MONEY_PREFIXES)->contains(fn (string $prefix) => str_starts_with($line->code, $prefix))))->values();
    }

    /**
     * The receipt a credit belongs to: the one whose number it names, else
     * the latest one of a supplier it names, on an account the credit takes
     * off, not after it.
     *
     * @param  Collection<string, \stdClass>  $receipts
     * @param  array<string, array<int, true>>  $accounts
     * @param  Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, Acquisition>>  $articles
     */
    private function receiptOf(\stdClass $credit, Collection $receipts, array $accounts, Collection $articles): ?string
    {
        $text = mb_strtolower(trim(($credit->reference ?? '').' '.($credit->description ?? '').' '.$credit->lines->pluck('description')->filter()->implode(' ')));
        $fits = $receipts
            ->filter(fn (\stdClass $r) => substr((string) $r->date, 0, 10) <= $credit->date)
            ->filter(fn (\stdClass $r) => $credit->lines->contains(fn (\stdClass $l) => Money::isPositive((string) $l->credit) && isset($accounts[$r->id][$l->account_id])))
            ->sortByDesc('date');

        foreach ($fits as $id => $receipt) {
            $number = $this->numberOf($receipt->reference);
            if ($number !== null && preg_match('/(?<![\d.])'.preg_quote($number, '/').'(?![\d.])/', $text) === 1) {
                return $id;
            }
        }
        foreach ($fits as $id => $receipt) {
            $suppliers = $articles[$id]->pluck('supplier')->filter()->map(fn (string $s) => mb_strtolower(trim($s)))->unique();
            if ($suppliers->contains(fn (string $s) => $s !== '' && str_contains($text, $s))) {
                return $id;
            }
        }

        return null;
    }

    /** The running number at the end of a reference, as "1141" in "BAN-20261005-1141". */
    private function numberOf(?string $reference): ?string
    {
        return $reference !== null && preg_match('/(\d+)\s*$/', $reference, $m) === 1 ? $m[1] : null;
    }

    /**
     * @param  Collection<int, string>  $entryIds
     * @return Collection<string, Collection<int, \stdClass>>
     */
    private function linesOf(Collection $entryIds): Collection
    {
        return DB::table('transaction_lines')
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->whereIn('transaction_lines.journal_entry_id', $entryIds)
            ->get(['transaction_lines.id', 'transaction_lines.journal_entry_id', 'transaction_lines.account_id', 'transaction_lines.debit', 'transaction_lines.credit', 'transaction_lines.description', 'accounts.code'])
            ->groupBy('journal_entry_id');
    }
}
