<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Projects\Models\Acquisition;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Where a booking counts, so that it counts once (2026-10-21).
 *
 * A cost block carries only what nobody else carries. A share of a line
 * leaves the block when it is allocated to a job or an internal job (it
 * counts there), to a person (it is charged to that person) or when the
 * receipt is an acquisition charged to people (it is charged there). The
 * internal job "General" (ALLG) only marks a line as a general cost: it
 * stays in the block, unless a person is named with it.
 */
class GeneralCostsService
{
    /** Fixed assets: what an acquisition receipt puts here is not in a block. */
    private const ASSET_PREFIXES = ['15', '16', '17'];

    /**
     * @return array{
     *     lines: array<int, array{block_id: int, amount: string, at_jobs: string, at_people: array<string, string>, at_acquisitions: string, in_block: string}>,
     *     blocks: array<int, string>,
     *     people: array<string, string>,
     *     general_elsewhere: string,
     * }
     *
     * lines: per block line, cost positive, where its parts count.
     * blocks: per block, what leaves it (jobs, people and acquisitions).
     * people: per person, the costs allocated to them, acquisitions aside.
     * general_elsewhere: the net (income positive) of ALLG shares that count
     * in a block or at a person, to take off the internal job ALLG.
     */
    public function forPeriod(string $organizationId, int $year, string $from, string $to): array
    {
        $blockOf = [];
        BudgetGroup::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('year', $year)
            ->with('accounts:id')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->each(function (BudgetGroup $group) use (&$blockOf): void {
                foreach ($group->accounts as $account) {
                    $blockOf[(int) $account->id] ??= $group->id;
                }
            });

        $general = Project::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', AllocationCoverageService::GENERAL_CODE)
            ->value('id');
        $general = $general !== null ? (int) $general : null;

        $result = ['lines' => [], 'blocks' => [], 'people' => [], 'general_elsewhere' => '0.00'];
        if ($to < $from) {
            return $result;
        }

        $lines = DB::table('transaction_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('journal_entries.organization_id', $organizationId)
            ->where('journal_entries.is_posted', true)
            ->whereBetween('journal_entries.date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('journal_entries.type')->orWhere('journal_entries.type', '<>', 'year_end_closing'))
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->orderBy('transaction_lines.id')
            ->get(['transaction_lines.id', 'transaction_lines.journal_entry_id', 'transaction_lines.account_id', 'transaction_lines.debit', 'transaction_lines.credit', 'accounts.code', 'accounts.type']);

        $allocations = DB::table('line_allocations')
            ->whereIn('transaction_line_id', $lines->pluck('id'))
            ->orderBy('id')
            ->get(['transaction_line_id', 'cost_center_id', 'employee_id', 'share'])
            ->groupBy('transaction_line_id');

        // Acquisitions charged to people, per receipt; less what the receipt
        // puts on fixed assets, the rest comes off its block lines.
        $entryIds = $lines->pluck('journal_entry_id')->unique()->values();
        $acquired = [];
        Acquisition::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('journal_entry_id', $entryIds)
            ->whereHas('people')
            ->get(['journal_entry_id', 'net_amount'])
            ->each(function (Acquisition $a) use (&$acquired): void {
                $acquired[$a->journal_entry_id] = Money::add($acquired[$a->journal_entry_id] ?? '0.00', (string) $a->net_amount);
            });
        if ($acquired !== []) {
            DB::table('transaction_lines')
                ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
                ->whereIn('transaction_lines.journal_entry_id', array_keys($acquired))
                ->where(function ($q): void {
                    foreach (self::ASSET_PREFIXES as $prefix) {
                        $q->orWhere('accounts.code', 'like', $prefix.'%');
                    }
                })
                ->get(['transaction_lines.journal_entry_id', 'transaction_lines.debit', 'transaction_lines.credit'])
                ->each(function ($l) use (&$acquired): void {
                    $acquired[$l->journal_entry_id] = Money::subtract($acquired[$l->journal_entry_id], Money::subtract((string) $l->debit, (string) $l->credit));
                });
        }

        foreach ($lines as $line) {
            $block = $blockOf[(int) $line->account_id] ?? null;
            $isCost = $line->type === AccountType::Expense->value;
            // Cost positive for expenses, income positive for revenue.
            $amount = $isCost
                ? Money::subtract((string) $line->debit, (string) $line->credit)
                : Money::subtract((string) $line->credit, (string) $line->debit);
            $own = $allocations->get($line->id, collect())->values();
            $parts = LineAllocationService::splitAmount($amount, $own->map(fn ($a) => (string) $a->share)->all());
            $onAcquisition = isset($acquired[$line->journal_entry_id]);

            $atJobs = '0.00';
            $atPeople = [];
            foreach ($own as $index => $allocation) {
                $part = $parts[$index];
                $project = $allocation->cost_center_id !== null ? (int) $allocation->cost_center_id : null;
                $isGeneral = $project === null || $project === $general;
                if (! $isGeneral) {
                    $atJobs = Money::add($atJobs, $part);

                    continue;
                }
                // ALLG with a person, or a person alone: charged to the person,
                // unless the receipt is an acquisition, which charges already.
                $toPerson = $allocation->employee_id !== null && ! $onAcquisition && $isCost
                    && ($block !== null || ! str_starts_with((string) $line->code, '5'));
                if ($toPerson) {
                    $atPeople[$allocation->employee_id] = Money::add($atPeople[$allocation->employee_id] ?? '0.00', $part);
                    $result['people'][$allocation->employee_id] = Money::add($result['people'][$allocation->employee_id] ?? '0.00', $part);
                }
                if ($project === $general && ($block !== null || $toPerson)) {
                    $result['general_elsewhere'] = Money::add($result['general_elsewhere'], $isCost ? Money::negate($part) : $part);
                }
            }

            if ($block === null || ! $isCost) {
                continue;
            }

            $left = Money::subtract(Money::subtract($amount, $atJobs), array_reduce($atPeople, fn (string $s, string $p) => Money::add($s, $p), '0.00'));
            $atAcquisitions = '0.00';
            if ($onAcquisition && Money::isPositive($acquired[$line->journal_entry_id]) && Money::isPositive($left)) {
                $atAcquisitions = Money::compare($acquired[$line->journal_entry_id], $left) < 0 ? $acquired[$line->journal_entry_id] : $left;
                $acquired[$line->journal_entry_id] = Money::subtract($acquired[$line->journal_entry_id], $atAcquisitions);
                $left = Money::subtract($left, $atAcquisitions);
            }

            $result['lines'][(int) $line->id] = [
                'block_id' => $block,
                'amount' => $amount,
                'at_jobs' => $atJobs,
                'at_people' => $atPeople,
                'at_acquisitions' => $atAcquisitions,
                'in_block' => $left,
            ];
            $result['blocks'][$block] = Money::add($result['blocks'][$block] ?? '0.00', Money::subtract($amount, $left));
        }

        return $result;
    }
}
