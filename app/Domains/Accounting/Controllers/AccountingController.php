<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Actions\CancelJournalCorrectionAction;
use App\Domains\Accounting\Actions\PostJournalCorrectionAction;
use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Actions\UpdateJournalDraftAction;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Requests\StoreJournalEntryRequest;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Concerns\HandlesFlashErrorResponses;
use App\Http\Controllers\Controller;
use App\Support\CsvExportService;
use App\Support\Exceptions\DomainException;
use App\Support\PdfExportService;
use App\Support\QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Journal entry management: listing, creating, posting, and reversing entries.
 */
class AccountingController extends Controller
{
    use HandlesFlashErrorResponses;

    public function chartOfAccounts(Request $request): Response
    {
        $this->authorize('viewAny', Account::class);

        $query = Account::withCount('transactionLines')
            ->orderBy('code');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        $accounts = $query
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Account $a) => [
                ...$a->toArray(),
                'has_transactions' => $a->transaction_lines_count > 0,
            ]);

        $user = $request->user();

        return Inertia::render('Accounting/ChartOfAccounts', [
            'accounts' => $accounts,
            'query' => ['search' => $request->input('search', '')],
            'can' => [
                'create' => $user->can('create', Account::class),
                'edit' => $user->hasPermissionTo(Permission::AccountingEdit),
                'delete' => $user->hasPermissionTo(Permission::AccountingDelete),
            ],
            'accountTypes' => array_map(fn ($t) => ['value' => $t->value, 'label' => $t->value], AccountType::cases()),
        ]);
    }

    public function journalEntries(Request $request): Response
    {
        $this->authorize('viewAny', JournalEntry::class);

        $query = JournalEntry::with(['lines.account', 'lines.vatRate', 'lines.costCenter']);

        // Account is a filter over the entry's lines, so it cannot go through
        // QueryBuilder's exact-match filters, which only address base columns.
        if ($accountId = $request->input('filter.account_id')) {
            $query->whereHas('lines', fn ($q) => $q->where('account_id', $accountId));
        }

        $entries = QueryBuilder::for($query, $request)
            ->allowedSorts(['date', 'reference', 'description', 'is_posted'], 'date', 'desc')
            ->allowedFilters(['is_posted'])
            ->searchable(['reference', 'description', 'lines.description', 'lines.account.code', 'lines.account.name'])
            ->searchableNumeric(['lines.debit', 'lines.credit'])
            ->apply()
            ->paginate(config('accounting.pagination.default'))
            ->withQueryString();

        $corrections = JournalCorrection::query()
            ->where(function ($query) use ($entries) {
                $ids = $entries->pluck('id');
                $query->whereIn('original_journal_entry_id', $ids)
                    ->orWhereIn('reversal_journal_entry_id', $ids)
                    ->orWhereIn('replacement_journal_entry_id', $ids);
            })
            ->get(['id', 'status', 'original_journal_entry_id', 'reversal_journal_entry_id', 'replacement_journal_entry_id']);

        $entries->through(function (JournalEntry $entry) use ($corrections) {
            $correction = $corrections->first(fn (JournalCorrection $c) => in_array($entry->id, [
                $c->original_journal_entry_id, $c->reversal_journal_entry_id, $c->replacement_journal_entry_id,
            ], true));

            $role = match ($entry->id) {
                $correction?->original_journal_entry_id => 'original',
                $correction?->replacement_journal_entry_id => 'replacement',
                $correction?->reversal_journal_entry_id => 'reversal',
                default => null,
            };

            return [
                ...$entry->toArray(),
                ...$this->summariseJournalLines($entry),
                'correction_role' => $role,
                'correction_id' => $correction?->id,
                'correction_status' => $correction?->status?->value,
            ];
        });

        $accounts = Account::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->display_name,
                'type' => $a->type->value,
            ]);

        $user = $request->user();

        return Inertia::render('Accounting/JournalEntries', [
            'entries' => $entries,
            'accounts' => $accounts,
            'query' => [
                'sort' => $request->input('sort', 'date'),
                'direction' => $request->input('direction', 'desc'),
                'search' => $request->input('search', ''),
                'filter' => [
                    'is_posted' => $request->input('filter.is_posted', ''),
                    'account_id' => $request->input('filter.account_id', ''),
                ],
            ],
            'can' => [
                'create' => $user?->can('create', JournalEntry::class) ?? false,
                'edit' => $user?->hasPermissionTo(Permission::AccountingEdit) ?? false,
                'delete' => $user?->hasPermissionTo(Permission::AccountingDelete) ?? false,
            ],
        ]);
    }

    /**
     * Condense an entry's lines into the flat debit/credit/amount shape the
     * journal list shows on a single row.
     *
     * A plain two-line entry names both accounts outright. A split entry can
     * only name the side that has a single account; the other side is left
     * null and the row is marked as a split so the table can say how many
     * positions are hidden behind it.
     *
     * @return array<string, mixed>
     */
    private function summariseJournalLines(JournalEntry $entry): array
    {
        $lines = $entry->lines;

        $debits = $lines->filter(fn (TransactionLine $line) => (float) $line->debit > 0);
        $credits = $lines->filter(fn (TransactionLine $line) => (float) $line->credit > 0);

        $describe = fn (TransactionLine $line) => $line->account === null ? null : [
            'id' => $line->account->id,
            'code' => $line->account->code,
            'name' => $line->account->display_name,
        ];

        $vatCodes = $lines->map(fn (TransactionLine $line) => $line->vatRate?->code)->filter()->unique();
        $costCentres = $lines->map(fn (TransactionLine $line) => $line->costCenter?->code)->filter()->unique();

        return [
            'debit_account' => $debits->count() === 1 ? $describe($debits->first()) : null,
            'credit_account' => $credits->count() === 1 ? $describe($credits->first()) : null,
            'amount' => number_format($lines->sum(fn (TransactionLine $line) => (float) $line->debit), 2, '.', ''),
            'line_count' => $lines->count(),
            'is_split' => $lines->count() > 2,
            'vat_code' => $vatCodes->count() === 1 ? $vatCodes->first() : null,
            'vat_amount' => number_format($lines->sum(fn (TransactionLine $line) => (float) $line->vat_amount), 2, '.', ''),
            'cost_center' => $costCentres->count() === 1 ? $costCentres->first() : null,
            'line_description' => $lines->first(fn (TransactionLine $line) => filled($line->description))?->description,
        ];
    }

    public function showJournalEntry(Request $request, JournalEntry $journalEntry): Response
    {
        $this->authorize('view', $journalEntry);

        $journalEntry->load('lines.account');

        $correction = JournalCorrection::query()
            ->where('original_journal_entry_id', $journalEntry->id)
            ->orWhere('replacement_journal_entry_id', $journalEntry->id)
            ->orWhere('reversal_journal_entry_id', $journalEntry->id)
            ->with(['original.lines.account', 'reversal.lines.account', 'replacement.lines.account'])
            ->first();

        $correctionRole = match ($journalEntry->id) {
            $correction?->original_journal_entry_id => 'original',
            $correction?->replacement_journal_entry_id => 'replacement',
            $correction?->reversal_journal_entry_id => 'reversal',
            default => null,
        };

        $accounts = Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->display_name,
                'type' => $a->type->value,
            ]);

        $user = $request->user();

        return Inertia::render('Accounting/JournalEntryShow', [
            'entry' => $journalEntry,
            'correction' => $correction,
            'correctionRole' => $correctionRole,
            'accounts' => $accounts,
            'can' => [
                'correct' => $user?->can('correct', $journalEntry) ?? false,
                'reverse' => $user?->can('reverse', $journalEntry) ?? false,
            ],
        ]);
    }

    public function prepareCorrection(
        Request $request,
        JournalEntry $journalEntry,
        PrepareJournalCorrectionAction $prepareCorrection,
    ): RedirectResponse {
        $this->authorize('correct', $journalEntry);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'correction_date' => ['required', 'date'],
        ]);

        try {
            $correction = $prepareCorrection->execute(
                original: $journalEntry,
                reason: $validated['reason'],
                correctionDate: $validated['correction_date'],
                source: JournalCorrectionSource::Web,
                userId: $request->user()?->id,
            );
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal-entries.show', $correction->replacement_journal_entry_id)
            ->with('success', __('app.journal_correction_prepared'));
    }

    public function updateCorrectionReplacement(
        Request $request,
        JournalCorrection $journalCorrection,
        UpdateJournalDraftAction $updateJournalDraft,
    ): RedirectResponse {
        $this->authorize('correct', $journalCorrection->original);

        if (! $journalCorrection->isDraft()) {
            return redirect()->route('accounting.journal')
                ->with('error', __('app.journal_correction_not_draft'));
        }

        $orgId = app(CurrentOrganization::class)->id();

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('organization_id', $orgId)
                    ->where('is_active', true),
            ],
            'lines.*.debit' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.credit' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $lines = array_map(fn (array $line) => new JournalLineData(
            accountId: (string) $line['account_id'],
            debit: (string) ($line['debit'] ?? '0'),
            credit: (string) ($line['credit'] ?? '0'),
            description: $line['description'] ?? null,
        ), $validated['lines']);

        $entryData = new JournalEntryData(
            date: $validated['date'],
            reference: $validated['reference'] ?? null,
            description: $validated['description'] ?? null,
            lines: $lines,
        );

        try {
            $updateJournalDraft->execute($journalCorrection->replacement, $entryData);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal-entries.show', $journalCorrection->replacement_journal_entry_id)
            ->with('success', __('app.journal_entry_updated'));
    }

    public function postCorrection(
        JournalCorrection $journalCorrection,
        PostJournalCorrectionAction $postCorrection,
    ): RedirectResponse {
        $this->authorize('correct', $journalCorrection->original);

        try {
            $postCorrection->execute($journalCorrection);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal')
            ->with('success', __('app.journal_correction_posted'));
    }

    public function cancelCorrection(
        JournalCorrection $journalCorrection,
        CancelJournalCorrectionAction $cancelCorrection,
    ): RedirectResponse {
        $this->authorize('correct', $journalCorrection->original);

        try {
            $cancelCorrection->execute($journalCorrection);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal')
            ->with('success', __('app.journal_correction_cancelled'));
    }

    public function createJournalEntry(CurrentOrganization $currentOrg): Response
    {
        $this->authorize('create', JournalEntry::class);

        $accounts = Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->display_name,
                'type' => $a->type->value,
            ]);

        return Inertia::render('Accounting/JournalEntryCreate', [
            'accounts' => $accounts,
            'defaultDate' => now()->toDateString(),
        ]);
    }

    public function storeJournalEntry(
        StoreJournalEntryRequest $request,
        CurrentOrganization $currentOrg,
        LedgerService $ledger,
    ): RedirectResponse {
        $this->authorize('create', JournalEntry::class);

        $validated = $request->validated();
        $isPosted = (bool) $request->boolean('is_posted', true);

        $lines = array_map(fn (array $line) => new JournalLineData(
            accountId: (string) $line['account_id'],
            debit: (string) ($line['debit'] ?? '0'),
            credit: (string) ($line['credit'] ?? '0'),
            description: $line['description'] ?? null,
        ), $validated['lines']);

        $entryData = new JournalEntryData(
            date: $validated['date'],
            reference: $validated['reference'] ?? null,
            description: $validated['description'] ?? null,
            lines: $lines,
        );

        try {
            $isPosted
                ? $ledger->postEntry($currentOrg->id(), $entryData)
                : $ledger->createDraft($currentOrg->id(), $entryData);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal')
            ->with('success', __($isPosted ? 'app.journal_entry_posted' : 'app.journal_entry_draft_saved'));
    }

    public function updateJournalEntry(
        StoreJournalEntryRequest $request,
        JournalEntry $journalEntry,
        UpdateJournalDraftAction $updateJournalDraft,
    ): RedirectResponse {
        $this->authorize('update', $journalEntry);

        if ($journalEntry->is_posted) {
            return redirect()->route('accounting.journal')
                ->with('error', __('app.cannot_edit_posted_entry'));
        }

        $validated = $request->validated();

        $lines = array_map(fn (array $line) => new JournalLineData(
            accountId: (string) $line['account_id'],
            debit: (string) ($line['debit'] ?? '0'),
            credit: (string) ($line['credit'] ?? '0'),
            description: $line['description'] ?? null,
        ), $validated['lines']);

        $entryData = new JournalEntryData(
            date: $validated['date'],
            reference: $validated['reference'] ?? null,
            description: $validated['description'] ?? null,
            lines: $lines,
        );

        try {
            $updateJournalDraft->execute($journalEntry, $entryData);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal')
            ->with('success', __('app.journal_entry_updated'));
    }

    public function postJournalEntry(JournalEntry $journalEntry, LedgerService $ledger): RedirectResponse
    {
        $this->authorize('post', $journalEntry);

        try {
            $ledger->postDraft($journalEntry);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal')
            ->with('success', __('app.journal_entry_posted'));
    }

    public function reverseJournalEntry(JournalEntry $journalEntry, LedgerService $ledger): RedirectResponse
    {
        $this->authorize('reverse', $journalEntry);

        try {
            $ledger->reverseEntry($journalEntry);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal')
            ->with('success', __('app.journal_entry_reversed'));
    }

    public function destroyJournalEntry(JournalEntry $journalEntry, LedgerService $ledger): RedirectResponse
    {
        $this->authorize('delete', $journalEntry);

        try {
            $journalEntry->lines()->delete();
            $journalEntry->delete();
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal')
            ->with('success', __('app.journal_entry_deleted'));
    }

    public function trialBalance(Request $request, LedgerQueryService $ledgerService, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', Account::class);

        $orgId = $currentOrg->id();
        $asOfDate = $request->input('as_of_date', now()->toDateString());

        $balances = $ledgerService->trialBalance($orgId, $asOfDate);

        return Inertia::render('Accounting/TrialBalance', [
            'balances' => $balances,
            'asOfDate' => $asOfDate,
        ]);
    }

    public function exportTrialBalance(
        Request $request,
        LedgerQueryService $ledgerService,
        CurrentOrganization $currentOrg,
        PdfExportService $pdf,
        CsvExportService $csv,
        string $format,
    ): HttpResponse {
        $this->authorize('viewAny', Account::class);

        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $orgId = $currentOrg->id();
        $asOfDate = $request->input('as_of_date', now()->toDateString());
        $balances = $ledgerService->trialBalance($orgId, $asOfDate);
        $org = $currentOrg->get();

        if ($format === 'csv') {
            $headers = ['Code', 'Account', 'Type', 'Debit', 'Credit'];
            $rows = array_map(fn ($row) => [
                $row['account_code'],
                $row['account_name'],
                $row['account_type'] instanceof AccountType
                    ? $row['account_type']->value
                    : $row['account_type'],
                $row['debit'],
                $row['credit'],
            ], $balances);

            return $csv->export($headers, $rows, "trial-balance-{$asOfDate}.csv");
        }

        return $pdf->download('exports.trial-balance', [
            'organization' => $org,
            'asOfDate' => $asOfDate,
            'balances' => $balances,
        ], "trial-balance-{$asOfDate}.pdf");
    }

    public function exportJournalEntries(
        Request $request,
        CurrentOrganization $currentOrg,
        PdfExportService $pdf,
        CsvExportService $csv,
        string $format,
    ): HttpResponse {
        $this->authorize('viewAny', JournalEntry::class);

        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $orgId = $currentOrg->id();
        $from = $request->input('from', now()->startOfYear()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $entries = JournalEntry::query()
            ->where('is_posted', true)
            ->whereBetween('date', [$from, $to])
            ->with('lines.account')
            ->orderBy('date')
            ->orderBy('created_at')
            ->get();

        $org = $currentOrg->get();

        if ($format === 'csv') {
            $headers = ['Date', 'Reference', 'Description', 'Account Code', 'Account Name', 'Debit', 'Credit'];
            $rows = [];
            foreach ($entries as $entry) {
                foreach ($entry->lines as $line) {
                    $rows[] = [
                        $entry->date->format('Y-m-d'),
                        $entry->reference,
                        $entry->description,
                        $line->account->code ?? '',
                        $line->account->name ?? '',
                        (string) $line->debit,
                        (string) $line->credit,
                    ];
                }
            }

            return $csv->export($headers, $rows, "journal-entries-{$from}-{$to}.csv");
        }

        return $pdf->download('exports.journal-entries', [
            'organization' => $org,
            'fromDate' => $from,
            'toDate' => $to,
            'entries' => $entries,
        ], "journal-entries-{$from}-{$to}.pdf");
    }
}
