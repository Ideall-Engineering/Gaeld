<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\AllocationSource;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\AllocationReview;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectShare;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Requests\ProjectRequest;
use App\Domains\Projects\Services\AllocationCoverageService;
use App\Domains\Projects\Services\LineAllocationService;
use App\Domains\Projects\Services\PlanActualService;
use App\Domains\Projects\Services\ProjectOverviewService;
use App\Http\Controllers\Controller;
use App\Support\CsvExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Projects and areas of an organization (spec 009, User Story 1).
 */
class ProjectController extends Controller
{
    public function index(Request $request, ProjectOverviewService $overview, AllocationCoverageService $coverage, PlanActualService $plans, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();
        $year = $this->year($request);
        $steer = $user?->can('steer', Project::class) ?? false;

        return Inertia::render('Projects/Index', [
            'projects' => ProjectQuery::tree(withShares: $steer),
            'overview' => $overview->overview($currentOrg->id(), "{$year}-01-01", "{$year}-12-31"),
            // Spec 010: whether every receipt of the year has its place.
            'coverage' => $user?->can('allocate', Project::class) ? $coverage->forYear($currentOrg->id(), $year) : null,
            // Spec 010: the general costs (cost blocks) below the jobs.
            'plan' => $plans->forYear($currentOrg->id(), $year, withKeys: $steer),
            'blockOptions' => ProjectQuery::blockOptions(),
            'hasPreviousYear' => BudgetGroup::query()->where('year', $year - 1)->exists(),
            'year' => $year,
            'contacts' => Contact::query()->orderBy('name')->get(['id', 'name']),
            'employees' => ProjectQuery::employeeOptions(),
            'kinds' => array_map(fn (ProjectKind $k) => ['value' => $k->value, 'label' => $k->label()], ProjectKind::cases()),
            'statuses' => array_map(fn (ProjectStatus $s) => ['value' => $s->value, 'label' => $s->label()], ProjectStatus::cases()),
            'openReviews' => AllocationReview::query()->whereNull('resolved_at')->count(),
            'can' => [
                'manage' => $user?->can('create', Project::class) ?? false,
                'allocate' => $user?->can('allocate', Project::class) ?? false,
                'steer' => $steer,
            ],
        ]);
    }

    /**
     * The overview of a year as CSV, one row per project.
     */
    public function export(Request $request, ProjectOverviewService $overview, CurrentOrganization $currentOrg, CsvExportService $csv): HttpResponse
    {
        $this->authorize('viewAny', Project::class);

        $year = $this->year($request);
        $data = $overview->overview($currentOrg->id(), "{$year}-01-01", "{$year}-12-31");

        $headers = [
            __('app.code'), __('app.name'), __('app.project_area'), __('app.project_kind'), __('app.status'),
            __('app.project_customer'), __('app.project_responsible'), __('app.project_target_date'), __('app.project_deadline'),
            __('app.project_billing_month'), __('app.project_expected_amount'), __('app.revenue'), __('app.expenses'),
            __('app.project_net'), __('app.project_invoiced'), __('app.project_received'), __('app.project_open'), __('app.project_payment'),
        ];

        $rows = array_map(fn (array $row) => [
            $row['code'], $row['name'], $row['area'], __('app.project_kind_'.$row['kind']), __('app.project_status_'.$row['status']),
            $row['customer'], $row['responsible'], $row['target_date'], __('app.project_deadline_'.$row['deadline']),
            $row['billing_month'], $row['expected'], $row['revenue'], $row['expenses'],
            $row['net'], $row['invoiced'], $row['received'], $row['open'], $row['payment'] === null ? '' : __('app.project_payment_'.$row['payment']),
        ], $data['rows']);

        return $csv->export($headers, $rows, "projects-{$year}.csv");
    }

    public function store(ProjectRequest $request, CurrentOrganization $currentOrg, LineAllocationService $allocations): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validated();
        $lineId = $validated['allocate_line_id'] ?? null;
        if ($lineId !== null) {
            $this->authorize('allocate', Project::class);
        }

        $project = DB::transaction(function () use ($request, $validated, $currentOrg, $lineId, $allocations): Project {
            $project = Project::create([
                ...collect($validated)->except(['shares', 'allocate_line_id'])->all(),
                'organization_id' => $currentOrg->id(),
                'is_active' => $request->boolean('is_active', true),
            ]);
            $this->syncShares($request, $project, $validated);

            // Spec 010: a job started from a receipt in the allocate inbox
            // takes that receipt right away.
            if ($lineId !== null) {
                $line = TransactionLine::query()
                    ->whereKey($lineId)
                    ->whereHas('journalEntry', fn ($q) => $q->where('organization_id', $currentOrg->id()))
                    ->firstOrFail();
                $allocations->replaceForEntry(
                    JournalEntry::query()->findOrFail($line->journal_entry_id),
                    [(int) $line->id => $allocations->withProject((int) $line->id, $project->id, AllocationSource::Manual)],
                    AllocationSource::Manual,
                    $request->user()?->id,
                );
            }

            return $project;
        });

        return back()->with('success', $lineId !== null
            ? __('app.job_created_with_receipt', ['code' => $project->code])
            : __('app.saved'));
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validated();
        $project->update([
            ...collect($validated)->except('shares')->all(),
            'is_active' => $request->boolean('is_active', $project->is_active),
        ]);
        $this->syncShares($request, $project, $validated);

        return back()->with('success', __('app.saved'));
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        if ($project->children()->exists()) {
            return back()->withErrors(['project' => __('app.cannot_delete_with_children')]);
        }

        $used = LineAllocation::query()->where('cost_center_id', $project->id)->exists()
            || InvoiceProject::query()->where('cost_center_id', $project->id)->exists();

        if ($used) {
            return back()->withErrors(['project' => __('app.project_delete_used')]);
        }

        $project->delete();

        // From the job's own page there is nothing to go back to.
        return ($request->boolean('to_index') ? redirect()->route('projects.index') : back())->with('success', __('app.deleted'));
    }

    /**
     * Owners set how a project's value is shared (spec 009, stage 5); the
     * field is ignored for everyone else.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncShares(Request $request, Project $project, array $validated): void
    {
        if (! array_key_exists('shares', $validated) || ! ($request->user()?->can('steer', Project::class) ?? false)) {
            return;
        }

        DB::transaction(function () use ($project, $validated): void {
            ProjectShare::query()->where('cost_center_id', $project->id)->delete();
            foreach ($validated['shares'] as $share) {
                ProjectShare::create([
                    'organization_id' => $project->organization_id,
                    'cost_center_id' => $project->id,
                    'employee_id' => $share['employee_id'],
                    'share' => $share['share'],
                ]);
            }
        });
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }
}
