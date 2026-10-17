<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Exceptions\InvalidAllocationException;
use App\Domains\Projects\Models\AllocationReview;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the projects and people a journal line belongs to
 * (spec 009).
 *
 * Nothing here touches transaction_lines, journal_entries or vat_entries,
 * and nothing here asks whether an entry is locked: an allocation is a
 * reporting fact and may change at any time.
 */
class LineAllocationService
{
    /**
     * Check that the allocations of one line are well formed and belong to
     * the organization. Whether a project is still active is a question for
     * the caller: carried-over allocations may point at closed projects.
     *
     * @param  array<int, LineAllocationData>  $allocations
     *
     * @throws InvalidAllocationException
     */
    public function validate(string $organizationId, array $allocations): void
    {
        $total = '0';
        $seen = [];

        foreach ($allocations as $allocation) {
            if ($allocation->projectId === null && $allocation->employeeId === null) {
                throw new InvalidAllocationException(__('app.allocation_needs_target'));
            }

            if (! is_numeric($allocation->share)
                || bccomp($allocation->share, '0', 4) <= 0
                || bccomp($allocation->share, '100', 4) > 0) {
                throw new InvalidAllocationException(__('app.allocation_share_range'));
            }

            $key = ($allocation->projectId ?? '-').'|'.($allocation->employeeId ?? '-');
            if (isset($seen[$key])) {
                throw new InvalidAllocationException(__('app.allocation_duplicate'));
            }
            $seen[$key] = true;

            $total = bcadd($total, $allocation->share, 4);
        }

        if (bccomp($total, '100', 4) > 0) {
            throw new InvalidAllocationException(__('app.allocation_total_exceeds'));
        }

        $projectIds = array_values(array_unique(array_filter(array_map(fn (LineAllocationData $a) => $a->projectId, $allocations))));
        if ($projectIds !== []) {
            $found = Project::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $projectIds)
                ->count();
            if ($found !== count($projectIds)) {
                throw new InvalidAllocationException(__('app.allocation_unknown_project'));
            }
        }

        $employeeIds = array_values(array_unique(array_filter(array_map(fn (LineAllocationData $a) => $a->employeeId, $allocations))));
        if ($employeeIds !== []) {
            $found = Employee::withoutGlobalScopes()
                ->withTrashed()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $employeeIds)
                ->count();
            if ($found !== count($employeeIds)) {
                throw new InvalidAllocationException(__('app.allocation_unknown_employee'));
            }
        }
    }

    /**
     * Replace the allocations of one line. Null leaves the line untouched.
     *
     * @param  array<int, LineAllocationData>|null  $allocations
     *
     * @throws InvalidAllocationException
     */
    public function writeFor(
        TransactionLine $line,
        string $organizationId,
        ?array $allocations,
        AllocationSource $source = AllocationSource::Manual,
    ): void {
        if ($allocations === null) {
            return;
        }

        $this->validate($organizationId, $allocations);

        LineAllocation::withoutGlobalScopes()
            ->where('transaction_line_id', $line->id)
            ->get()
            ->each->delete();

        foreach ($allocations as $allocation) {
            LineAllocation::create([
                'organization_id' => $organizationId,
                'transaction_line_id' => $line->id,
                'cost_center_id' => $allocation->projectId,
                'employee_id' => $allocation->employeeId,
                'share' => $allocation->share,
                'source' => ($allocation->source ?? $source)->value,
            ]);
        }
    }

    /**
     * Replace the allocations of some lines of an entry, whatever state the
     * entry is in. Lines not named keep what they have. Resolves an open
     * review for the entry, since a person has now decided.
     *
     * @param  array<int, array<int, LineAllocationData>>  $byLineId
     *
     * @throws InvalidAllocationException
     */
    public function replaceForEntry(JournalEntry $entry, array $byLineId, AllocationSource $source, ?int $userId = null): void
    {
        $lineIds = TransactionLine::query()
            ->where('journal_entry_id', $entry->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach (array_keys($byLineId) as $lineId) {
            if (! in_array((int) $lineId, $lineIds, true)) {
                throw new InvalidAllocationException(__('app.allocation_unknown_line'));
            }
        }

        DB::transaction(function () use ($entry, $byLineId, $source, $userId): void {
            $before = $this->snapshot($entry);

            foreach ($byLineId as $lineId => $allocations) {
                $line = TransactionLine::query()->whereKey($lineId)->firstOrFail();
                $this->writeFor($line, $entry->organization_id, $allocations, $source);
            }

            $after = $this->snapshot($entry);

            if ($before !== $after) {
                activity()
                    ->performedOn($entry)
                    ->withProperties([
                        'organization_id' => $entry->organization_id,
                        'old' => $before,
                        'attributes' => $after,
                    ])
                    ->event('allocations_replaced')
                    ->log('Allocations replaced');
            }

            AllocationReview::withoutGlobalScopes()
                ->where('journal_entry_id', $entry->id)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'resolved_by' => $userId]);
        });
    }

    /**
     * Allocations of every line of an entry, keyed by line id, in a plain
     * form fit for snapshots and comparisons.
     *
     * @return array<int, array<int, array{project_id: int|null, employee_id: string|null, share: string}>>
     */
    public function snapshot(JournalEntry|string $entry): array
    {
        $entryId = $entry instanceof JournalEntry ? $entry->id : $entry;

        return LineAllocation::withoutGlobalScopes()
            ->join('transaction_lines', 'transaction_lines.id', '=', 'line_allocations.transaction_line_id')
            ->where('transaction_lines.journal_entry_id', $entryId)
            ->orderBy('line_allocations.transaction_line_id')
            ->orderBy('line_allocations.id')
            ->get(['line_allocations.*'])
            ->groupBy('transaction_line_id')
            ->map(fn (Collection $group) => $group
                ->map(fn (LineAllocation $a) => LineAllocationData::fromModel($a)->toArray())
                ->values()
                ->all())
            ->all();
    }

    /**
     * The lines of an entry with their allocations, captured before the
     * lines are deleted and rebuilt.
     *
     * @return array<int, array{account_id: string, debit: string, credit: string, allocations: array<int, LineAllocationData>}>
     */
    public function captureLines(JournalEntry $entry): array
    {
        $allocations = LineAllocation::withoutGlobalScopes()
            ->join('transaction_lines', 'transaction_lines.id', '=', 'line_allocations.transaction_line_id')
            ->where('transaction_lines.journal_entry_id', $entry->id)
            ->orderBy('line_allocations.id')
            ->get(['line_allocations.*'])
            ->groupBy('transaction_line_id');

        return TransactionLine::query()
            ->where('journal_entry_id', $entry->id)
            ->orderBy('id')
            ->get()
            ->map(fn (TransactionLine $line) => [
                'account_id' => (string) $line->account_id,
                'debit' => Money::of((string) $line->debit),
                'credit' => Money::of((string) $line->credit),
                'allocations' => ($allocations->get($line->id) ?? collect())
                    ->map(fn (LineAllocation $a) => LineAllocationData::fromModel($a))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Allocations for each line of a copied entry (a reversal or a
     * correction draft), in line order.
     *
     * @return array<int, array<int, LineAllocationData>>
     */
    public function forLinesOf(JournalEntry $entry): array
    {
        return array_map(
            fn (array $line) => $line['allocations'],
            $this->captureLines($entry),
        );
    }

    /**
     * Attach allocations to lines that were just rebuilt.
     *
     * Lines that bring their own allocations get exactly those; the others
     * inherit as {@see inherit()} decides. What is left over goes on the
     * review list.
     *
     * @param  array<int, array{account_id: string, debit: string, credit: string, allocations: array<int, LineAllocationData>}>  $before
     * @param  array<int, array{0: TransactionLine, 1: JournalLineData}>  $rebuilt
     */
    public function applyAfterRebuild(JournalEntry $entry, array $before, array $rebuilt, AllocationSource $source): void
    {
        [$lines, $leftOver] = $this->inherit($before, array_map(fn (array $pair) => $pair[1], $rebuilt));

        foreach ($rebuilt as $index => [$line]) {
            $this->writeFor($line, $entry->organization_id, $lines[$index]->allocations, $source);
        }

        $this->flagLeftOver($entry, $leftOver);
    }

    /**
     * Give lines that say nothing about allocations those of the one old
     * line they clearly replace: same account, side and amount, or failing
     * that the only old line on the same account and side. Lines that bring
     * their own allocations keep them and only use up their predecessor.
     *
     * Returns the lines, now with inherited allocations, and the old lines
     * whose allocations found no clear successor. Nothing is left over when
     * every new line brought its own allocations: the caller decided.
     *
     * @param  array<int, array{account_id: string, debit: string, credit: string, allocations: array<int, LineAllocationData>}>  $before
     * @param  array<int, JournalLineData>  $lines
     * @return array{0: array<int, JournalLineData>, 1: array<int, array{account_id: string, debit: string, credit: string, allocations: array<int, LineAllocationData>}>}
     */
    public function inherit(array $before, array $lines): array
    {
        $remaining = $before;
        $silent = [];

        foreach ($lines as $index => $line) {
            if ($line->allocations !== null) {
                $match = $this->findPredecessor($remaining, $line, $lines);
                if ($match !== null) {
                    unset($remaining[$match]);
                }
            } else {
                $silent[] = $index;
            }
        }

        foreach ($silent as $index) {
            $match = $this->findPredecessor($remaining, $lines[$index], $lines);
            if ($match === null) {
                continue;
            }

            $carried = array_map(fn (LineAllocationData $a) => new LineAllocationData(
                projectId: $a->projectId,
                employeeId: $a->employeeId,
                share: $a->share,
                source: AllocationSource::Carried,
            ), $remaining[$match]['allocations']);
            $lines[$index] = $lines[$index]->withAllocations($carried !== [] ? $carried : null);
            unset($remaining[$match]);
        }

        $leftOver = $silent === []
            ? []
            : array_values(array_filter($remaining, fn (array $line) => $line['allocations'] !== []));

        return [$lines, $leftOver];
    }

    /**
     * Put old allocations without a clear successor on the review list.
     *
     * @param  array<int, array{account_id: string, debit: string, credit: string, allocations: array<int, LineAllocationData>}>  $leftOver
     */
    public function flagLeftOver(JournalEntry $entry, array $leftOver): void
    {
        if ($leftOver === []) {
            return;
        }

        $previous = array_map(fn (array $line) => [
            'account_id' => $line['account_id'],
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'allocations' => array_map(fn (LineAllocationData $a) => $a->toArray(), $line['allocations']),
        ], $leftOver);

        $open = AllocationReview::withoutGlobalScopes()
            ->where('journal_entry_id', $entry->id)
            ->whereNull('resolved_at')
            ->first();

        if ($open !== null) {
            $open->update(['previous' => [...$open->previous, ...$previous]]);

            return;
        }

        AllocationReview::create([
            'organization_id' => $entry->organization_id,
            'journal_entry_id' => $entry->id,
            'reason' => AllocationReview::REASON_LINES_REBUILT,
            'previous' => $previous,
        ]);
    }

    /**
     * Amount of a line that each of its allocations stands for, with the
     * rounding difference on the last share when the line is fully
     * allocated.
     *
     * @param  array<int, string>  $shares  percentages in allocation order
     * @return array<int, string>
     */
    public static function splitAmount(string $amount, array $shares): array
    {
        $parts = [];
        $total = '0';
        $assigned = '0.00';

        foreach ($shares as $index => $share) {
            $parts[$index] = Money::percentage($amount, $share);
            $total = bcadd($total, $share, 4);
            $assigned = Money::add($assigned, $parts[$index]);
        }

        if ($parts !== [] && bccomp($total, '100', 4) === 0) {
            $last = array_key_last($parts);
            $parts[$last] = Money::add($parts[$last], Money::subtract($amount, $assigned));
        }

        return $parts;
    }

    /**
     * The old line a new one clearly replaces, or null when that is not
     * clear.
     *
     * @param  array<int, array{account_id: string, debit: string, credit: string, allocations: array<int, LineAllocationData>}>  $candidates
     * @param  array<int, JournalLineData>  $newLines  all rebuilt lines, to tell a split from a change
     */
    private function findPredecessor(array $candidates, JournalLineData $data, array $newLines): ?int
    {
        $debit = Money::of($data->debit);
        $credit = Money::of($data->credit);
        $isDebit = Money::isPositive($debit);
        $accountId = (string) $data->accountId;

        $exact = array_keys(array_filter($candidates, fn (array $old) => $old['account_id'] === $accountId
            && Money::compare($old['debit'], $debit) === 0
            && Money::compare($old['credit'], $credit) === 0));

        if ($exact !== []) {
            // Identical lines are interchangeable only if they were
            // allocated alike; otherwise there is no telling which is which.
            $variants = array_unique(array_map(
                fn (int $key) => json_encode(array_map(fn (LineAllocationData $a) => $a->toArray(), $candidates[$key]['allocations'])),
                $exact,
            ));

            return count($variants) === 1 ? $exact[0] : null;
        }

        $sameSideOld = array_keys(array_filter($candidates, fn (array $old) => $old['account_id'] === $accountId
            && Money::isPositive($old['debit']) === $isDebit));

        $sameSideNew = array_filter($newLines, fn (JournalLineData $line) => (string) $line->accountId === $accountId
            && Money::isPositive(Money::of($line->debit)) === $isDebit);

        return count($sameSideOld) === 1 && count($sameSideNew) === 1 ? $sameSideOld[0] : null;
    }
}
