<?php

namespace App\Domains\Projects\Services;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Support\AccountDisplayName;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Projects\Models\Project;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Revenue and expense lines without a project, each with a suggestion where
 * there is a reason for one (spec 009, stage 2):
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
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function unallocated(string $organizationId, string $from, string $to): array
    {
        $lines = DB::table('transaction_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->where('journal_entries.organization_id', $organizationId)
            ->where('journal_entries.is_posted', true)
            ->whereBetween('journal_entries.date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('journal_entries.type')->orWhere('journal_entries.type', '<>', 'year_end_closing'))
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('line_allocations')
                ->whereColumn('line_allocations.transaction_line_id', 'transaction_lines.id')
                ->whereNotNull('line_allocations.cost_center_id'))
            ->orderBy('journal_entries.date')
            ->orderBy('transaction_lines.id')
            ->limit(self::LIMIT)
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
            ]);

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

        return $lines->map(function ($line) use ($projects, $invoices, $history, $organizationId): array {
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
                'type' => $line->type,
                'amount' => $line->type === AccountType::Revenue->value
                    ? Money::subtract((string) $line->credit, (string) $line->debit)
                    : Money::subtract((string) $line->debit, (string) $line->credit),
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
