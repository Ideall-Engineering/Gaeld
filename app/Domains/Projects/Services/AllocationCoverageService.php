<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Whether every receipt of a year has its place (spec 010, US1).
 *
 * Each posted revenue and expense line of the year falls into one category:
 *
 * - assigned: allocated to a job (an internal one counts as general);
 * - general:  its account belongs to a cost block of the year, so the block
 *             says who bears it;
 * - payroll:  personnel expense (account class 5) without a cost block,
 *             which comes from the payroll and is left out;
 * - open:     everything else, to be dealt with.
 *
 * Both halves of every reversal and of every posted correction are left out
 * altogether, as the journal does with "hide corrections", and so are
 * year-end closing entries.
 */
class AllocationCoverageService
{
    /** Code of the internal job that takes single general receipts. */
    public const GENERAL_CODE = 'ALLG';

    /** Account class of personnel expense in the Swiss SME chart. */
    public const PAYROLL_CLASS = '5';

    public const ASSIGNED = 'assigned';

    public const GENERAL = 'general';

    public const PAYROLL = 'payroll';

    public const OPEN = 'open';

    /**
     * Counts and amounts per side and category, plus how many lines were
     * left out as reversals or corrections.
     *
     * @return array{
     *     year: int,
     *     revenue: array<string, array{count: int, amount: string}>,
     *     expense: array<string, array{count: int, amount: string}>,
     *     open: int,
     *     done: int,
     *     total: int,
     *     corrections: int,
     * }
     */
    public function forYear(string $organizationId, int $year): array
    {
        $lines = $this->lines($organizationId, $year);
        $summary = [];
        foreach (['revenue' => AccountType::Revenue->value, 'expense' => AccountType::Expense->value] as $side => $type) {
            foreach ([self::ASSIGNED, self::GENERAL, self::PAYROLL, self::OPEN] as $category) {
                $matching = $lines->filter(fn (\stdClass $line) => $line->type === $type && $line->category === $category);
                $summary[$side][$category] = [
                    'count' => $matching->count(),
                    'amount' => $matching->reduce(fn (string $sum, \stdClass $line) => Money::add($sum, (string) $line->amount), '0.00'),
                ];
            }
        }

        $open = $summary['revenue'][self::OPEN]['count'] + $summary['expense'][self::OPEN]['count'];
        $done = 0;
        foreach (['revenue', 'expense'] as $side) {
            $done += $summary[$side][self::ASSIGNED]['count'] + $summary[$side][self::GENERAL]['count'];
        }

        $corrections = $this->base($organizationId, $year)->count() - $lines->count();

        return [
            'year' => $year,
            ...$summary,
            'open' => $open,
            'done' => $done,
            'total' => $open + $done,
            'corrections' => $corrections,
        ];
    }

    /**
     * Every counted line of the year with its category and amount (positive
     * is income for revenue and a cost for expenses), oldest first.
     *
     * @return Collection<int, \stdClass>
     */
    public function lines(string $organizationId, int $year): Collection
    {
        $internal = Project::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('kind', ProjectKind::Internal->value)
            ->pluck('id')
            ->all();

        $query = $this->base($organizationId, $year);
        $this->withoutCorrections($query);

        return $query
            ->orderBy('journal_entries.date')
            ->orderBy('transaction_lines.id')
            ->get([
                'transaction_lines.id',
                'transaction_lines.journal_entry_id',
                'transaction_lines.account_id',
                'transaction_lines.debit',
                'transaction_lines.credit',
                'transaction_lines.description AS line_description',
                'journal_entries.date',
                'journal_entries.reference',
                'journal_entries.description',
                'accounts.code',
                'accounts.name',
                'accounts.type',
            ])
            ->map(function ($line) {
                $line->amount = $line->type === AccountType::Revenue->value
                    ? Money::subtract((string) $line->credit, (string) $line->debit)
                    : Money::subtract((string) $line->debit, (string) $line->credit);

                return $line;
            })
            ->pipe(fn (Collection $lines) => $this->categorize($lines, $organizationId, $year, $internal));
    }

    /**
     * The internal job "General", created on first use.
     */
    public function generalProject(string $organizationId): Project
    {
        $project = Project::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', self::GENERAL_CODE)
            ->first();

        if ($project !== null) {
            if (! $project->is_active) {
                $project->update(['is_active' => true]);
            }

            return $project;
        }

        return Project::withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'code' => self::GENERAL_CODE,
            'name' => __('app.general_project_name'),
            'kind' => ProjectKind::Internal->value,
            'status' => ProjectStatus::InProgress->value,
            'is_active' => true,
        ]);
    }

    /**
     * Account ids that belong to a cost block of the year.
     *
     * @return array<int, bool>
     */
    public function blockAccounts(string $organizationId, int $year): array
    {
        return DB::table('budget_group_accounts')
            ->join('budget_groups', 'budget_groups.id', '=', 'budget_group_accounts.budget_group_id')
            ->where('budget_groups.organization_id', $organizationId)
            ->where('budget_groups.year', $year)
            ->pluck('budget_group_accounts.account_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * @param  Collection<int, \stdClass>  $lines
     * @param  array<int, int>  $internal
     * @return Collection<int, \stdClass>
     */
    private function categorize(Collection $lines, string $organizationId, int $year, array $internal): Collection
    {
        $blocks = $this->blockAccounts($organizationId, $year);

        $allocated = DB::table('line_allocations')
            ->whereIn('transaction_line_id', $lines->pluck('id'))
            ->whereNotNull('cost_center_id')
            ->get(['transaction_line_id', 'cost_center_id'])
            ->groupBy('transaction_line_id');

        return $lines->map(function ($line) use ($blocks, $allocated, $internal) {
            $projects = $allocated->get($line->id, collect())->pluck('cost_center_id')->map(fn ($id) => (int) $id);

            $line->category = match (true) {
                $projects->isNotEmpty() && $projects->every(fn (int $id) => in_array($id, $internal, true)) => self::GENERAL,
                $projects->isNotEmpty() => self::ASSIGNED,
                isset($blocks[(int) $line->account_id]) => self::GENERAL,
                str_starts_with((string) $line->code, self::PAYROLL_CLASS) => self::PAYROLL,
                default => self::OPEN,
            };

            return $line;
        });
    }

    /**
     * Posted revenue and expense lines of the year, without year-end closing.
     */
    private function base(string $organizationId, int $year): Builder
    {
        return DB::table('transaction_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('journal_entries.organization_id', $organizationId)
            ->where('journal_entries.is_posted', true)
            ->whereBetween('journal_entries.date', ["{$year}-01-01", "{$year}-12-31"])
            ->where(fn (Builder $q) => $q->whereNull('journal_entries.type')->orWhere('journal_entries.type', '<>', 'year_end_closing'))
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value]);
    }

    /**
     * Leaves out both halves of every reversal pair and the original and
     * storno of each posted correction, as the journal's "hide corrections"
     * filter does (AccountingController).
     */
    private function withoutCorrections(Builder $query): Builder
    {
        return $query->whereNull('journal_entries.reversal_of_entry_id')
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('journal_entries as reversals')
                ->whereColumn('reversals.reversal_of_entry_id', 'journal_entries.id')
                ->where('reversals.is_posted', true))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('journal_corrections')
                ->where('journal_corrections.status', 'posted')
                ->where(fn (Builder $inner) => $inner
                    ->whereColumn('journal_corrections.original_journal_entry_id', 'journal_entries.id')
                    ->orWhereColumn('journal_corrections.reversal_journal_entry_id', 'journal_entries.id')));
    }
}
