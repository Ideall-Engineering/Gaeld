<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Support\AccountDisplayName;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Open revenue and expense lines, each with a suggestion where there is a
 * reason for one (spec 009, stage 2; spec 010):
 *
 * 1. the line books an invoice that has a project;
 * 2. the line books an invoice whose customer has exactly one open project;
 * 3. the same account with the same entry text was allocated before.
 *
 * Suggestions are only offered; nothing is allocated until a person accepts.
 */
class AllocationSuggestionService
{
    /** Lines listed at most, oldest first. */
    public const LIMIT = 500;

    public function __construct(
        private InvoiceProjectService $invoiceProjects,
        private AllocationCoverageService $coverage,
    ) {}

    /**
     * The open lines of a year (spec 010): not yet allocated, not general
     * through a cost block, not payroll and not part of a correction. With
     * $withGeneral the general ones are listed too (after the open ones), to
     * move one of them to a job after all; $type keeps revenue or expense
     * lines only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unallocated(string $organizationId, int $year, bool $withGeneral = false, ?string $type = null): array
    {
        $categories = $withGeneral
            ? [AllocationCoverageService::OPEN, AllocationCoverageService::GENERAL]
            : [AllocationCoverageService::OPEN];

        $lines = $this->coverage->lines($organizationId, $year)
            ->filter(fn ($line) => in_array($line->category, $categories, true) && ($type === null || $line->type === $type))
            // Open ones first, the general ones after them.
            ->sortBy(fn ($line) => $line->category === AllocationCoverageService::OPEN ? 0 : 1, SORT_REGULAR)
            ->take(self::LIMIT)
            ->values();

        // The cost block that makes a general line general, to show it.
        $blocks = DB::table('budget_group_accounts')
            ->join('budget_groups', 'budget_groups.id', '=', 'budget_group_accounts.budget_group_id')
            ->where('budget_groups.organization_id', $organizationId)
            ->where('budget_groups.year', $year)
            ->pluck('budget_groups.name', 'budget_group_accounts.account_id');

        $projects = Project::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $invoices = Invoice::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('journal_entry_id', $lines->pluck('journal_entry_id')->unique())
            ->get()
            ->keyBy('journal_entry_id');

        $history = $this->history($organizationId);

        return $lines->map(function ($line) use ($projects, $invoices, $history, $organizationId, $blocks): array {
            $suggestion = null;
            $invoice = $invoices->get($line->journal_entry_id);

            if ($invoice !== null) {
                $projectId = $this->invoiceProjects->projectIdFor($invoice);
                if ($projectId !== null) {
                    $suggestion = [$projectId, 'invoice'];
                } elseif (($customerProject = $this->invoiceProjects->suggestionForCustomer($organizationId, $invoice->customer_id)) !== null) {
                    $suggestion = [$customerProject, 'customer'];
                }
            }

            if ($suggestion === null) {
                $previous = $history[$this->key((int) $line->account_id, (string) ($line->description ?? ''))] ?? null;
                if ($previous !== null) {
                    $suggestion = [$previous, 'history'];
                }
            }

            $project = $suggestion !== null ? $projects->get($suggestion[0]) : null;

            return [
                'line_id' => (int) $line->id,
                'entry_id' => $line->journal_entry_id,
                'date' => substr((string) $line->date, 0, 10),
                'reference' => $line->reference,
                'description' => $line->description,
                'line_description' => $line->line_description,
                'account' => $line->code.' '.AccountDisplayName::for((string) $line->code, (string) $line->name),
                'account_id' => (int) $line->account_id,
                'type' => $line->type,
                'category' => $line->category,
                'block' => $line->category === AllocationCoverageService::GENERAL ? ($blocks[$line->account_id] ?? null) : null,
                'amount' => $line->amount,
                'suggestion' => $project === null ? null : [
                    'project_id' => $project->id,
                    'label' => "{$project->code} {$project->name}",
                    'source' => $suggestion[1],
                ],
            ];
        })->all();
    }

    /**
     * The project last allocated to each combination of account and entry
     * text, to suggest it for the next line of the same kind.
     *
     * @return array<string, int>
     */
    private function history(string $organizationId): array
    {
        $rows = DB::table('line_allocations')
            ->join('transaction_lines', 'transaction_lines.id', '=', 'line_allocations.transaction_line_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->join('cost_centers', 'cost_centers.id', '=', 'line_allocations.cost_center_id')
            ->where('line_allocations.organization_id', $organizationId)
            ->where('cost_centers.is_active', true)
            ->orderBy('journal_entries.date')
            ->orderBy('line_allocations.id')
            ->get(['transaction_lines.account_id', 'journal_entries.description', 'line_allocations.cost_center_id']);

        $history = [];
        foreach ($rows as $row) {
            // Later rows win: the most recent allocation is the suggestion.
            $history[$this->key((int) $row->account_id, (string) ($row->description ?? ''))] = (int) $row->cost_center_id;
        }

        return $history;
    }

    /**
     * Account plus the entry text without numbers, dates and punctuation,
     * so "Microsoft 365 10/2026" and "Microsoft 365 11/2026" match.
     */
    private function key(int $accountId, string $description): string
    {
        $text = Str::of($description)->lower()->replaceMatches('/[^\pL]+/u', ' ')->squish()->value();

        return $accountId.'|'.$text;
    }
}
