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

        $byAccount = [];
        $byProject = [];

        foreach ($this->allocatedParts($project->organization_id, $from, $to, $projectIds) as $part) {
            $key = $part['type'].'|'.$part['code'];
            $byAccount[$key] ??= ['type' => $part['type'], 'code' => $part['code'], 'name' => $part['name'], 'amount' => '0.00'];
            $byAccount[$key]['amount'] = Money::add($byAccount[$key]['amount'], $part['amount']);

            $side = $part['type'] === AccountType::Revenue->value ? 'revenue' : 'expenses';
            $byProject[$part['project_id']] ??= ['revenue' => '0.00', 'expenses' => '0.00'];
            $byProject[$part['project_id']][$side] = Money::add($byProject[$part['project_id']][$side], $part['amount']);
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

    /**
     * Revenue and expenses per project (each project on its own, without
     * its sub-projects) for every project of the organization.
     *
     * @return array<int, array{revenue: string, expenses: string}>
     */
    public function totalsByProject(string $organizationId, string $from, string $to): array
    {
        $totals = [];

        foreach ($this->allocatedParts($organizationId, $from, $to) as $part) {
            $side = $part['type'] === AccountType::Revenue->value ? 'revenue' : 'expenses';
            $totals[$part['project_id']] ??= ['revenue' => '0.00', 'expenses' => '0.00'];
            $totals[$part['project_id']][$side] = Money::add($totals[$part['project_id']][$side], $part['amount']);
        }

        return $totals;
    }

    /**
     * The part of each account allocated to the given projects, by account
     * code; positive is a cost for expenses and income for revenue.
     *
     * @param  array<int, int>  $projectIds
     * @return array<string, string>
     */
    public function totalsByAccount(string $organizationId, string $from, string $to, array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $totals = [];
        foreach ($this->allocatedParts($organizationId, $from, $to, $projectIds) as $part) {
            $totals[$part['code']] = Money::add($totals[$part['code']] ?? '0.00', $part['amount']);
        }

        return $totals;
    }

    /**
     * What each allocation of a posted revenue or expense line in the period
     * stands for. Positive is good for revenue and a cost for expenses. All
     * allocations of a line take part in the split, so the rounding lands on
     * the same share whichever projects are asked for.
     *
     * @param  array<int, int>|null  $projectIds  only parts for these projects; null for all
     * @return \Generator<int, array{project_id: int, type: string, code: string, name: string, amount: string}>
     */
    private function allocatedParts(string $organizationId, string $from, string $to, ?array $projectIds = null): \Generator
    {
        $lines = DB::table('transaction_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('journal_entries.organization_id', $organizationId)
            ->where('journal_entries.is_posted', true)
            ->whereBetween('journal_entries.date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('journal_entries.type')->orWhere('journal_entries.type', '<>', 'year_end_closing'))
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('line_allocations')
                ->whereColumn('line_allocations.transaction_line_id', 'transaction_lines.id')
                ->whereNotNull('line_allocations.cost_center_id')
                ->when($projectIds !== null, fn ($q) => $q->whereIn('line_allocations.cost_center_id', $projectIds)))
            ->get([
                'transaction_lines.id',
                'transaction_lines.debit',
                'transaction_lines.credit',
                'accounts.code',
                'accounts.name',
                'accounts.type',
            ]);

        $allocations = LineAllocation::withoutGlobalScopes()
            ->whereIn('transaction_line_id', $lines->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'transaction_line_id', 'cost_center_id', 'share'])
            ->groupBy('transaction_line_id');

        foreach ($lines as $line) {
            /** @var object{id:int,debit:string,credit:string,code:string,name:string,type:string} $line */
            $amount = $line->type === AccountType::Revenue->value
                ? Money::subtract((string) $line->credit, (string) $line->debit)
                : Money::subtract((string) $line->debit, (string) $line->credit);

            $lineAllocations = $allocations->get($line->id, collect())->values();
            $parts = LineAllocationService::splitAmount(
                $amount,
                $lineAllocations->map(fn (LineAllocation $a) => (string) $a->share)->all(),
            );

            foreach ($lineAllocations as $index => $allocation) {
                if ($allocation->cost_center_id === null
                    || ($projectIds !== null && ! in_array($allocation->cost_center_id, $projectIds, true))) {
                    continue;
                }

                yield [
                    'project_id' => (int) $allocation->cost_center_id,
                    'type' => (string) $line->type,
                    'code' => (string) $line->code,
                    'name' => AccountDisplayName::for((string) $line->code, (string) $line->name),
                    'amount' => $parts[$index],
                ];
            }
        }
    }
}
