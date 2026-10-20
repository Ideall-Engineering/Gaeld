<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Services\AllocationCoverageService;
use App\Domains\Projects\Services\AllocationSuggestionService;
use App\Domains\Projects\Services\LineAllocationService;
use App\Http\Controllers\Concerns\AnswersApiCalls;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Open revenue and expense lines, with suggestions, to work through like an
 * inbox (spec 009, User Story 9; spec 010, US2).
 */
class UnallocatedLinesController extends Controller
{
    use AnswersApiCalls;

    public function index(Request $request, AllocationSuggestionService $suggestions, AllocationCoverageService $coverage, CurrentOrganization $currentOrg): Response|JsonResponse
    {
        $this->authorize('allocate', Project::class);

        $year = $request->integer('year', (int) now()->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;

        // Spec 010: revenue and expenses on two tabs. Expenses mostly sit in
        // cost blocks, so that tab shows the general ones too unless asked not
        // to: that is where a cost is moved to a job.
        $type = $request->input('tab') === 'expense' ? AccountType::Expense->value : AccountType::Revenue->value;
        $withGeneral = $request->has('general') ? $request->boolean('general') : $type === AccountType::Expense->value;

        if ($this->wantsApiAnswer()) {
            return response()->json(['data' => [
                'year' => $year,
                'tab' => $type,
                'with_general' => $withGeneral,
                'lines' => $suggestions->unallocated($currentOrg->id(), $year, $withGeneral, $type),
                'coverage' => $coverage->forYear($currentOrg->id(), $year),
            ]]);
        }

        return Inertia::render('Projects/Unallocated', [
            'lines' => $suggestions->unallocated($currentOrg->id(), $year, $withGeneral, $type),
            'tab' => $type,
            'coverage' => $coverage->forYear($currentOrg->id(), $year),
            'limit' => AllocationSuggestionService::LIMIT,
            'year' => $year,
            'withGeneral' => $withGeneral,
            'projects' => ProjectQuery::options(),
            'blocks' => BudgetGroup::query()->where('year', $year)->orderBy('position')->orderBy('name')->get(['id', 'name']),
            // For "new job" straight from a receipt.
            'formOptions' => [
                'projects' => ProjectQuery::tree(),
                'contacts' => Contact::query()->orderBy('name')->get(['id', 'name']),
                'employees' => ProjectQuery::employeeOptions(),
                'kinds' => array_map(fn (ProjectKind $k) => ['value' => $k->value, 'label' => $k->label()], ProjectKind::cases()),
                'statuses' => array_map(fn (ProjectStatus $s) => ['value' => $s->value, 'label' => $s->label()], ProjectStatus::cases()),
            ],
            'can' => [
                'manage' => $request->user()?->can('create', Project::class) ?? false,
                'steer' => $request->user()?->can('steer', Project::class) ?? false,
            ],
        ]);
    }

    /**
     * Allocate the chosen lines, each wholly to its chosen project.
     */
    public function apply(Request $request, LineAllocationService $allocations, CurrentOrganization $currentOrg): RedirectResponse|JsonResponse
    {
        $this->authorize('allocate', Project::class);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.line_id' => ['required', 'integer'],
            'items.*.project_id' => [
                'required',
                'integer',
                Rule::exists('cost_centers', 'id')->where('organization_id', $currentOrg->id())->where('is_active', true),
            ],
        ]);

        $lines = TransactionLine::query()
            ->whereIn('id', array_column($validated['items'], 'line_id'))
            ->whereHas('journalEntry', fn ($q) => $q->where('organization_id', $currentOrg->id()))
            ->get(['id', 'journal_entry_id'])
            ->keyBy('id');

        $byEntry = [];
        foreach ($validated['items'] as $item) {
            $line = $lines->get((int) $item['line_id']);
            if ($line === null) {
                continue;
            }
            $byEntry[$line->journal_entry_id][(int) $line->id] = $allocations->withProject((int) $line->id, (int) $item['project_id'], AllocationSource::Bulk);
        }

        DB::transaction(function () use ($byEntry, $allocations, $request): void {
            foreach ($byEntry as $entryId => $byLine) {
                $entry = JournalEntry::query()->findOrFail($entryId);
                $allocations->replaceForEntry($entry, $byLine, AllocationSource::Bulk, $request->user()?->id);
            }
        });

        $count = array_sum(array_map('count', $byEntry));

        return $this->answer(__('app.allocations_bulk_done', ['count' => $count]), ['allocated' => $count]);
    }

    /**
     * Marks a line as general (spec 010, US2). For an expense the whole
     * account can go into a cost block, an existing one or a new one named
     * after the account, so every line of it counts as general; otherwise the
     * single line goes to the internal job "General".
     */
    public function general(Request $request, LineAllocationService $allocations, AllocationCoverageService $coverage, CurrentOrganization $currentOrg): RedirectResponse|JsonResponse
    {
        $this->authorize('allocate', Project::class);

        $orgId = $currentOrg->id();
        $validated = $request->validate([
            'line_id' => ['required', 'integer'],
            'scope' => ['required', Rule::in(['line', 'account'])],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'budget_group_id' => [
                'nullable',
                'integer',
                Rule::exists('budget_groups', 'id')->where('organization_id', $orgId)->where('year', (int) $request->input('year')),
            ],
        ]);

        $line = TransactionLine::query()
            ->whereKey($validated['line_id'])
            ->whereHas('journalEntry', fn ($q) => $q->where('organization_id', $orgId))
            ->with('account:id,code,name,type')
            ->firstOrFail();

        if ($validated['scope'] === 'line') {
            DB::transaction(function () use ($line, $allocations, $coverage, $orgId, $request): void {
                $general = $coverage->generalProject($orgId);
                $entry = JournalEntry::query()->findOrFail($line->journal_entry_id);
                $allocations->replaceForEntry($entry, [
                    (int) $line->id => $allocations->withProject((int) $line->id, $general->id, AllocationSource::Bulk),
                ], AllocationSource::Bulk, $request->user()?->id);
            });

            return $this->answer(__('app.general_line_done'), ['line_id' => (int) $line->id, 'scope' => 'line']);
        }

        $this->authorize('create', Project::class);
        $account = $line->account;
        if ($account === null || $account->type !== AccountType::Expense) {
            return $this->wantsApiAnswer()
                ? response()->json(['message' => __('app.general_account_expense_only'), 'errors' => ['scope' => [__('app.general_account_expense_only')]]], 422)
                : back()->withErrors(['scope' => __('app.general_account_expense_only')]);
        }

        $year = (int) $validated['year'];
        if (isset($coverage->blockAccounts($orgId, $year)[(int) $account->id])) {
            return $this->answer(__('app.general_account_done', ['account' => $account->code]), ['account' => $account->code, 'scope' => 'account']);
        }

        DB::transaction(function () use ($validated, $account, $orgId, $year): void {
            $group = isset($validated['budget_group_id'])
                ? BudgetGroup::query()->whereKey($validated['budget_group_id'])->firstOrFail()
                : BudgetGroup::query()->firstOrCreate(
                    ['organization_id' => $orgId, 'year' => $year, 'name' => trim("{$account->code} {$account->name}")],
                    ['kind' => BudgetGroupKind::Running->value, 'position' => (int) BudgetGroup::query()->where('year', $year)->max('position') + 1],
                );
            $group->accounts()->syncWithoutDetaching([$account->id]);
        });

        return $this->answer(__('app.general_account_done', ['account' => $account->code]), ['account' => $account->code, 'scope' => 'account']);
    }
}
