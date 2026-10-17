<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Support\AccountDisplayName;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Revenue, expenses and contribution of a project and everything below it
 * (spec 009, User Story 5).
 *
 * Reads only allocations: what a line contributes is its amount times the
 * share allocated to the project, with the rounding on the last share of a
 * fully allocated line. Year-end closing entries are left out, since they
 * only move the year's result to equity.
 */
class ProjectReportService
{
    /**
     * @return array{
     *     revenue: array<int, array{code: string, name: string, amount: string}>,
     *     expenses: array<int, array{code: string, name: string, amount: string}>,
     *     projects: array<int, array{id: int, code: string, name: string, revenue: string, expenses: string, contribution: string}>,
     *     total_revenue: string,
     *     total_expenses: string,
     *     contribution: string,
     * }
     */
    public function forProject(Project $project, string $from, string $to): array
    {
        $projectIds = $project->selfAndDescendantIds();

        $lines = DB::table('transaction_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('journal_entries.organization_id', $project->organization_id)
            ->where('journal_entries.is_posted', true)
            ->whereBetween('journal_entries.date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('journal_entries.type')->orWhere('journal_entries.type', '<>', 'year_end_closing'))
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('line_allocations')
                ->whereColumn('line_allocations.transaction_line_id', 'transaction_lines.id')
                ->whereIn('line_allocations.cost_center_id', $projectIds))
            ->get([
                'transaction_lines.id',
                'transaction_lines.debit',
                'transaction_lines.credit',
                'accounts.code',
                'accounts.name',
                'accounts.type',
            ]);

        // Every allocation of those lines, also to other projects, so the
        // rounding lands on the same share whichever project is reported.
        $allocations = LineAllocation::withoutGlobalScopes()
            ->whereIn('transaction_line_id', $lines->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'transaction_line_id', 'cost_center_id', 'share'])
            ->groupBy('transaction_line_id');

        $byAccount = [];
        $byProject = [];

        foreach ($lines as $line) {
            /** @var object{id:int,debit:string,credit:string,code:string,name:string,type:string} $line */
            $isRevenue = $line->type === AccountType::Revenue->value;
            // Positive is good for revenue, a cost for expenses.
            $amount = $isRevenue
                ? Money::subtract((string) $line->credit, (string) $line->debit)
                : Money::subtract((string) $line->debit, (string) $line->credit);

            $lineAllocations = $allocations->get($line->id, collect())->values();
            $parts = LineAllocationService::splitAmount(
                $amount,
                $lineAllocations->map(fn (LineAllocation $a) => (string) $a->share)->all(),
            );

            foreach ($lineAllocations as $index => $allocation) {
                if (! in_array($allocation->cost_center_id, $projectIds, true)) {
                    continue;
                }

                $part = $parts[$index];
                $key = $line->type.'|'.$line->code;
                $byAccount[$key] ??= ['type' => $line->type, 'code' => (string) $line->code, 'name' => AccountDisplayName::for((string) $line->code, (string) $line->name), 'amount' => '0.00'];
                $byAccount[$key]['amount'] = Money::add($byAccount[$key]['amount'], $part);

                $projectKey = (int) $allocation->cost_center_id;
                $byProject[$projectKey] ??= ['revenue' => '0.00', 'expenses' => '0.00'];
                $byProject[$projectKey][$isRevenue ? 'revenue' : 'expenses'] = Money::add($byProject[$projectKey][$isRevenue ? 'revenue' : 'expenses'], $part);
            }
        }

        ksort($byAccount);

        $revenue = [];
        $expenses = [];
        $totalRevenue = '0.00';
        $totalExpenses = '0.00';

        foreach ($byAccount as $row) {
            $entry = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $row['amount']];
            if ($row['type'] === AccountType::Revenue->value) {
                $revenue[] = $entry;
                $totalRevenue = Money::add($totalRevenue, $row['amount']);
            } else {
                $expenses[] = $entry;
                $totalExpenses = Money::add($totalExpenses, $row['amount']);
            }
        }

        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(function (Project $p) use ($byProject) {
                $revenue = $byProject[$p->id]['revenue'] ?? '0.00';
                $expenses = $byProject[$p->id]['expenses'] ?? '0.00';

                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'revenue' => $revenue,
                    'expenses' => $expenses,
                    'contribution' => Money::subtract($revenue, $expenses),
                ];
            })
            ->all();

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'projects' => $projects,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'contribution' => Money::subtract($totalRevenue, $totalExpenses),
        ];
    }
}
