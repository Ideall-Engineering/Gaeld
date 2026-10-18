<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Actions\AmendPostedJournalEntryAction;
use App\Domains\Accounting\Actions\CancelJournalCorrectionAction;
use App\Domains\Accounting\Actions\DeletePostedJournalEntryAction;
use App\Domains\Accounting\Actions\PostJournalCorrectionAction;
use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Actions\UpdateJournalDraftAction;
use App\Domains\Accounting\DTOs\JournalEntryChangeContext;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\JournalCorrectionSource;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Exceptions\BankAmountChangeUnacknowledgedException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Queries\AccountQuery;
use App\Domains\Accounting\Requests\StoreJournalEntryRequest;
use App\Domains\Accounting\Rules\NotSystemReference;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Support\AllocationRules;
use App\Domains\Projects\Support\ProjectsModule;
use App\Http\Controllers\Concerns\HandlesFlashErrorResponses;
use App\Http\Controllers\Controller;
use App\Support\CsvExportService;
use App\Support\Exceptions\DomainException;
use App\Support\PdfExportService;
use App\Support\QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            'parentAccounts' => AccountQuery::forSelect(),
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

        $query = JournalEntry::with($this->lineRelations('lines.account', 'lines.vatRate', 'lines.costCenter'));

        // Account is a filter over the entry's lines, so it cannot go through
        // QueryBuilder's exact-match filters, which only address base columns.
        if ($accountId = $request->input('filter.account_id')) {
            $query->whereHas('lines', fn ($q) => $q->where('account_id', $accountId));
        }

        // Hides both halves of every reversal pair (storno and the entry it
        // reverses) and the original and storno of each posted guided
        // correction, which leaves the books as they read after the fix.
        if ($request->input('filter.corrections') === 'hide') {
            $query->whereNull('reversal_of_entry_id')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')
                    ->from('journal_entries as reversals')
                    ->whereColumn('reversals.reversal_of_entry_id', 'journal_entries.id')
                    ->where('reversals.is_posted', true))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')
                    ->from('journal_corrections')
                    ->where('journal_corrections.status', 'posted')
                    ->where(fn ($inner) => $inner
                        ->whereColumn('journal_corrections.original_journal_entry_id', 'journal_entries.id')
                        ->orWhereColumn('journal_corrections.reversal_journal_entry_id', 'journal_entries.id')));
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
            ->latest()
            ->orderByDesc('id')
            ->get(['id', 'status', 'original_journal_entry_id', 'reversal_journal_entry_id', 'replacement_journal_entry_id']);

        $user = $request->user();
        $editState = app(JournalEntryEditStateService::class);
        $mayAmend = $user?->hasPermissionTo(Permission::AccountingAmendPosted) ?? false;

        $entries->through(function (JournalEntry $entry) use ($corrections, $editState, $mayAmend) {
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
                ...$this->editStateFor($entry, $editState, $mayAmend),
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

        return Inertia::render('Accounting/JournalEntries', [
            'entries' => $entries,
            'accounts' => $accounts,
            ...$this->journalFormProps($editState, (string) app(CurrentOrganization::class)->id()),
            'recentChangesCount' => JournalEntryRevision::query()
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'query' => [
                'sort' => $request->input('sort', 'date'),
                'direction' => $request->input('direction', 'desc'),
                'search' => $request->input('search', ''),
                'filter' => [
                    'is_posted' => $request->input('filter.is_posted', ''),
                    'account_id' => $request->input('filter.account_id', ''),
                    'corrections' => $request->input('filter.corrections', ''),
                ],
            ],
            'can' => [
                'create' => $user?->can('create', JournalEntry::class) ?? false,
                'edit' => $user?->hasPermissionTo(Permission::AccountingEdit) ?? false,
                'delete' => $user?->hasPermissionTo(Permission::AccountingDelete) ?? false,
                'amend' => $mayAmend,
            ],
        ]);
    }

    /**
     * Whether projects are switched on and the current user may allocate
     * lines to them (spec 009).
     */
    private function mayAllocate(): bool
    {
        $org = app(CurrentOrganization::class);

        return $org->isBound()
            && ProjectsModule::enabledFor($org->get())
            && (request()->user()?->can('allocate', Project::class) ?? false);
    }

    /**
     * Relations to load on journal lines; allocations only where projects
     * are switched on, so answers elsewhere stay exactly as they were.
     *
     * @return array<int, string>
     */
    private function lineRelations(string ...$base): array
    {
        $org = app(CurrentOrganization::class);

        if ($org->isBound()
            && ProjectsModule::enabledFor($org->get())
            && (request()->user()?->can('viewAny', Project::class) ?? false)) {
            // 'lines.account' -> 'lines', 'replacement.lines.account' -> 'replacement.lines'
            $prefix = Str::beforeLast($base[0] ?? 'lines.account', '.');
            $base[] = "{$prefix}.allocations.project:id,code,name";
            $base[] = "{$prefix}.allocations.employee:id,first_name,last_name";
        }

        return array_values($base);
    }

    /**
     * What the journal entry form needs beyond the accounts: the VAT rates a
     * line can carry and whether posted entries are changeable at all.
     *
     * @return array<string, mixed>
     */
    private function journalFormProps(JournalEntryEditStateService $editState, string $organizationId): array
    {
        return [
            'vatRates' => VatRate::query()
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->get(['uuid', 'code', 'name', 'rate', 'is_active'])
                ->map(fn (VatRate $rate) => [
                    'uuid' => $rate->uuid,
                    'label' => trim("{$rate->code} {$rate->name}").' ('.rtrim(rtrim((string) $rate->rate, '0'), '.').' %)',
                    'rate' => (string) $rate->rate,
                    'is_active' => $rate->is_active,
                ]),
            'vatTypes' => array_map(fn (VatEntryType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ], VatEntryType::cases()),
            'postedEntryEditMode' => $editState->isFlexible($organizationId)
                ? PostedEntryEditMode::Flexible->value
                : PostedEntryEditMode::Strict->value,
            // Spec 009: null hides the project fields entirely.
            'allocationOptions' => $this->mayAllocate() ? [
                'projects' => ProjectQuery::tree(),
                'employees' => ProjectQuery::employeeOptions(),
            ] : null,
        ];
    }

    /**
     * A revision as the history views show it: who replaced the state, when,
     * from where and why, plus the state itself with account labels.
     *
     * @return array<string, mixed>
     */
    private function presentRevision(JournalEntryRevision $revision): array
    {
        $snapshot = $revision->snapshot;
        $accountIds = array_values(array_unique(array_map(
            fn (array $line) => $line['account_id'] ?? null,
            $snapshot['lines'] ?? [],
        )));
        $accounts = Account::query()->whereIn('id', array_filter($accountIds))->get(['id', 'code', 'name'])->keyBy('id');

        return [
            'id' => $revision->id,
            'journal_entry_id' => $revision->journal_entry_id,
            'revision' => $revision->revision,
            'kind' => $revision->kind->value,
            'reference' => $revision->reference,
            'reason' => $revision->reason,
            'source' => $revision->source->value,
            'user' => $revision->user?->name,
            'recorded_at' => $revision->created_at->toIso8601String(),
            'entry' => [
                'date' => isset($snapshot['entry']['date']) ? substr((string) $snapshot['entry']['date'], 0, 10) : null,
                'reference' => $snapshot['entry']['reference'] ?? null,
                'description' => $snapshot['entry']['description'] ?? null,
            ],
            'lines' => array_map(function (array $line) use ($accounts) {
                $account = $accounts->get($line['account_id'] ?? null);

                return [
                    'account' => $account === null ? null : ['code' => $account->code, 'name' => $account->display_name],
                    'debit' => (string) ($line['debit'] ?? '0'),
                    'credit' => (string) ($line['credit'] ?? '0'),
                    'description' => $line['description'] ?? null,
                    'vat_type' => $line['vat_type'] ?? null,
                    'vat_amount' => $line['vat_amount'] ?? null,
                ];
            }, $snapshot['lines'] ?? []),
        ];
    }

    /**
     * The in-place edit state the journal list and detail page show for a
     * posted entry: whether it can be amended, and if not, why.
     *
     * @return array{edit_state: string, edit_state_label: string, can_amend: bool, suggests_correction: bool, suggested_correction_date: string|null}
     */
    private function editStateFor(JournalEntry $entry, JournalEntryEditStateService $editState, bool $mayAmend): array
    {
        $state = $editState->stateFor($entry);

        return [
            'edit_state' => $state->value,
            'edit_state_label' => $state->label(),
            'can_amend' => $mayAmend && $state->isAmendable(),
            'suggests_correction' => $state->suggestsCorrection(),
            // Where a guided correction of this entry can land without
            // touching a settled VAT period or a closed year.
            'suggested_correction_date' => $entry->is_posted
                ? $editState->firstOpenDateOnOrAfter($entry->organization_id, $entry->date->toDateString())
                : null,
        ];
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
        $costCentres = $lines->flatMap(fn (TransactionLine $line) => $line->relationLoaded('allocations')
            ? [$line->costCenter?->code, ...$line->allocations->map(fn (LineAllocation $a) => $a->project?->code)]
            : [$line->costCenter?->code])->filter()->unique()->values();

        return [
            'debit_account' => $debits->count() === 1 ? $describe($debits->first()) : null,
            'credit_account' => $credits->count() === 1 ? $describe($credits->first()) : null,
            'amount' => number_format($lines->sum(fn (TransactionLine $line) => (float) $line->debit), 2, '.', ''),
            'line_count' => $lines->count(),
            'is_split' => $lines->count() > 2,
            'vat_code' => $vatCodes->count() === 1 ? $vatCodes->first() : null,
            'vat_amount' => number_format($lines->sum(fn (TransactionLine $line) => (float) $line->vat_amount), 2, '.', ''),
            // Several project codes are listed only where projects are on;
            // elsewhere a mixed entry keeps showing no cost centre, as before.
            'cost_center' => match (true) {
                $costCentres->count() === 1 => $costCentres->first(),
                $costCentres->count() > 1 && $lines->contains(fn (TransactionLine $line) => $line->relationLoaded('allocations')) => $costCentres->implode(', '),
                default => null,
            },
            'line_description' => $lines->first(fn (TransactionLine $line) => filled($line->description))?->description,
        ];
    }

    /**
     * Posted entries changed or deleted in the last 30 days, newest first,
     * so changes to figures that may already have been passed on are easy to
     * review.
     */
    public function journalEntryChanges(Request $request): Response
    {
        $this->authorize('viewAny', JournalEntryRevision::class);

        $revisions = JournalEntryRevision::query()
            ->with('user:id,name')
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')
            ->paginate(config('accounting.pagination.default'))
            ->withQueryString();

        $existing = JournalEntry::query()
            ->whereIn('id', $revisions->pluck('journal_entry_id'))
            ->pluck('id')
            ->flip();

        $revisions->through(fn (JournalEntryRevision $revision) => [
            ...$this->presentRevision($revision),
            'entry_exists' => $existing->has($revision->journal_entry_id),
        ]);

        return Inertia::render('Accounting/JournalEntryChanges', [
            'revisions' => $revisions,
        ]);
    }

    public function showJournalEntry(Request $request, JournalEntry $journalEntry): Response
    {
        $this->authorize('view', $journalEntry);

        $journalEntry->load($this->lineRelations('lines.account'));

        $correction = JournalCorrection::query()
            ->where('original_journal_entry_id', $journalEntry->id)
            ->orWhere('replacement_journal_entry_id', $journalEntry->id)
            ->orWhere('reversal_journal_entry_id', $journalEntry->id)
            ->with([
                ...$this->lineRelations('original.lines.account'),
                ...$this->lineRelations('reversal.lines.account'),
                ...$this->lineRelations('replacement.lines.account'),
            ])
            // A replacement corrected again is both the replacement of one
            // correction and the original of the next; the newer one wins.
            ->latest()
            ->orderByDesc('id')
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
        $editState = app(JournalEntryEditStateService::class);
        $mayAmend = $user?->hasPermissionTo(Permission::AccountingAmendPosted) ?? false;

        return Inertia::render('Accounting/JournalEntryShow', [
            'entry' => [
                ...$journalEntry->toArray(),
                ...$this->editStateFor($journalEntry, $editState, $mayAmend),
            ],
            'correction' => $correction,
            'correctionRole' => $correctionRole,
            'accounts' => $accounts,
            ...$this->journalFormProps($editState, $journalEntry->organization_id),
            'revisions' => $journalEntry->revisions()
                ->with('user:id,name')
                ->get()
                ->map(fn (JournalEntryRevision $revision) => $this->presentRevision($revision)),
            'can' => [
                'correct' => $user?->can('correct', $journalEntry) ?? false,
                'reverse' => $user?->can('reverse', $journalEntry) ?? false,
                'amend' => $mayAmend,
                'link_document' => $user?->can('linkDocument', $journalEntry) ?? false,
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
            'reference' => ['nullable', 'string', 'max:100', new NotSystemReference($journalCorrection->replacement->reference)],
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
            ...StoreJournalEntryRequest::lineVatRules($orgId),
            ...($this->mayAllocate()
                ? AllocationRules::for($orgId, keptProjectIds: [
                    ...AllocationRules::projectsOf($journalCorrection->replacement),
                    ...AllocationRules::projectsOf($journalCorrection->original),
                ], keptEmployeeIds: [
                    ...AllocationRules::employeesOf($journalCorrection->replacement),
                    ...AllocationRules::employeesOf($journalCorrection->original),
                ])
                : []),
        ]);

        $entryData = $this->journalEntryDataFrom($validated);

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

        $isPosted = (bool) $request->boolean('is_posted', true);
        $entryData = $this->journalEntryDataFrom($request->validated());

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
        AmendPostedJournalEntryAction $amendPostedEntry,
    ): RedirectResponse {
        $this->authorize($journalEntry->is_posted ? 'amend' : 'update', $journalEntry);

        $validated = $request->validated();
        $entryData = $this->journalEntryDataFrom($validated);

        // A posted entry is only changed against the revision it was shown
        // with; without one there is nothing to detect a concurrent change by.
        if ($journalEntry->is_posted && ! isset($validated['revision'])) {
            return $this->backWithError(__('app.journal_entry_stale_revision'));
        }

        try {
            if ($journalEntry->is_posted) {
                $amendPostedEntry->execute($journalEntry, $entryData, new JournalEntryChangeContext(
                    expectedRevision: (int) $validated['revision'],
                    source: JournalCorrectionSource::Web,
                    userId: $request->user()?->id,
                    reason: $validated['change_reason'] ?? null,
                    acknowledgeBankAmountChange: $request->boolean('acknowledge_bank_amount_change'),
                ));
            } else {
                $updateJournalDraft->execute($journalEntry, $entryData);
            }
        } catch (BankAmountChangeUnacknowledgedException $e) {
            // Asked back as a field error so the form can show the
            // confirmation next to the amounts instead of a toast.
            return back()->withErrors(['acknowledge_bank_amount_change' => $e->getMessage()]);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        // The detail page asks to come back to itself; only that one path
        // is accepted, so the parameter cannot become an open redirect.
        $returnTo = (string) $request->input('redirect_to', '');
        $target = $returnTo === "/accounting/journal-entries/{$journalEntry->id}"
            ? redirect($returnTo)
            : redirect()->route('accounting.journal');

        return $target->with('success', __($journalEntry->is_posted ? 'app.journal_entry_amended' : 'app.journal_entry_updated'));
    }

    /**
     * Set or clear the link to where the entry's receipt is kept. A pointer
     * only, so posted entries take it without a revision.
     */
    public function linkJournalEntryDocument(Request $request, JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('linkDocument', $journalEntry);

        $validated = $request->validate([
            'document_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
        ]);

        $journalEntry->update(['document_url' => $validated['document_url'] ?: null]);

        return back()->with('success', __('app.journal_entry_document_saved'));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function journalEntryDataFrom(array $validated): JournalEntryData
    {
        $mayAllocate = $this->mayAllocate();

        $lines = array_map(fn (array $line) => new JournalLineData(
            accountId: (string) $line['account_id'],
            debit: (string) ($line['debit'] ?? '0'),
            credit: (string) ($line['credit'] ?? '0'),
            description: $line['description'] ?? null,
            vatRateId: ($line['vat_type'] ?? null) !== null ? ($line['vat_rate_id'] ?? null) : null,
            vatAmount: ($line['vat_type'] ?? null) !== null && isset($line['vat_amount']) ? (string) $line['vat_amount'] : null,
            vatType: $line['vat_type'] ?? null,
            vatFigure: ($line['vat_type'] ?? null) !== null ? ($line['vat_figure'] ?? null) : null,
            allocations: AllocationRules::fromValidatedLine($line, $mayAllocate),
        ), $validated['lines']);

        return new JournalEntryData(
            date: $validated['date'],
            reference: $validated['reference'] ?? null,
            description: $validated['description'] ?? null,
            lines: $lines,
        );
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

    public function destroyJournalEntry(
        Request $request,
        JournalEntry $journalEntry,
        DeletePostedJournalEntryAction $deletePostedEntry,
    ): RedirectResponse {
        $this->authorize($journalEntry->is_posted ? 'deletePosted' : 'delete', $journalEntry);

        $validated = $request->validate([
            'revision' => ['nullable', 'integer', 'min:1'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($journalEntry->is_posted && ! isset($validated['revision'])) {
            return $this->backWithError(__('app.journal_entry_stale_revision'));
        }

        try {
            if ($journalEntry->is_posted) {
                $deletePostedEntry->execute($journalEntry, new JournalEntryChangeContext(
                    expectedRevision: (int) $validated['revision'],
                    source: JournalCorrectionSource::Web,
                    userId: $request->user()?->id,
                    reason: $validated['change_reason'] ?? null,
                ));
            } else {
                $journalEntry->lines()->delete();
                $journalEntry->delete();
            }
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal')
            ->with('success', __($journalEntry->is_posted ? 'app.journal_entry_posted_deleted' : 'app.journal_entry_deleted'));
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
