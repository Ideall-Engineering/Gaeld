<?php

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\BudgetGroup;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The revenue and expense lines of a year as a journal, each with its
 * place: job, project, cost block (spec 010, journal view).
 *
 * The lines are those the allocation coverage counts, so corrections and
 * year-end closing stay out here as well. Each row names the account on the
 * debit and on the credit side, as a Banana journal does: the line's own
 * account on its side, the accounts of the other side of the entry on the
 * other.
 */
class ProjectJournalService
{
    public function __construct(private AllocationCoverageService $coverage) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forYear(string $organizationId, int $year): array
    {
        $lines = $this->coverage->lines($organizationId, $year);
        if ($lines->isEmpty()) {
            return [];
        }

        $entryIds = $lines->pluck('journal_entry_id')->unique()->values();
        $others = DB::table('transaction_lines')
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->whereIn('transaction_lines.journal_entry_id', $entryIds)
            ->orderBy('transaction_lines.id')
            ->get(['transaction_lines.id', 'transaction_lines.journal_entry_id', 'transaction_lines.debit', 'transaction_lines.credit', 'accounts.code'])
            ->groupBy('journal_entry_id');

        $allocations = DB::table('line_allocations')
            ->whereIn('transaction_line_id', $lines->pluck('id'))
            ->orderBy('id')
            ->get(['transaction_line_id', 'cost_center_id', 'employee_id', 'share'])
            ->groupBy('transaction_line_id');

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
                    $blockOf[(int) $account->id] ??= $group->name;
                }
            });

        return $lines->map(function (\stdClass $line) use ($others, $allocations, $blockOf): array {
            $isDebit = Money::isPositive(Money::subtract((string) $line->debit, (string) $line->credit));
            $counter = collect($others->get($line->journal_entry_id, []))
                ->filter(fn (\stdClass $other) => (int) $other->id !== (int) $line->id)
                ->filter(fn (\stdClass $other) => $isDebit
                    ? Money::isPositive(Money::subtract((string) $other->credit, (string) $other->debit))
                    : Money::isPositive(Money::subtract((string) $other->debit, (string) $other->credit)))
                ->pluck('code')
                ->unique()
                ->values()
                ->all();

            return [
                'line_id' => (int) $line->id,
                'entry_id' => $line->journal_entry_id,
                'date' => (string) $line->date,
                'reference' => $line->reference,
                'document_url' => $line->document_url,
                'description' => $line->line_description ?: $line->description,
                'account' => ['id' => (int) $line->account_id, 'code' => (string) $line->code, 'name' => (string) $line->name],
                'type' => $line->type,
                'debit_account' => $isDebit ? (string) $line->code : implode(', ', $counter),
                'credit_account' => $isDebit ? implode(', ', $counter) : (string) $line->code,
                'amount' => Money::absoluteAmount(Money::subtract((string) $line->debit, (string) $line->credit)),
                // Signed for the result: income positive, a cost negative.
                'net' => $line->type === 'revenue' ? (string) $line->amount : Money::negate((string) $line->amount),
                'category' => $line->category,
                'block' => $blockOf[(int) $line->account_id] ?? null,
                'allocations' => collect($allocations->get($line->id, []))->map(fn (\stdClass $a) => [
                    'project_id' => $a->cost_center_id !== null ? (int) $a->cost_center_id : null,
                    'employee_id' => $a->employee_id,
                    'share' => (string) (float) $a->share,
                ])->values()->all(),
            ];
        })->values()->all();
    }
}
