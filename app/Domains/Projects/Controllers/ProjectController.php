<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\AllocationReview;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Requests\ProjectRequest;
use App\Domains\Projects\Services\ProjectOverviewService;
use App\Http\Controllers\Controller;
use App\Support\CsvExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Projects and areas of an organization (spec 009, User Story 1).
 */
class ProjectController extends Controller
{
    public function index(Request $request, ProjectOverviewService $overview, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();
        $year = $this->year($request);

        return Inertia::render('Projects/Index', [
            'projects' => ProjectQuery::tree(),
            'overview' => $overview->overview($currentOrg->id(), "{$year}-01-01", "{$year}-12-31"),
            'year' => $year,
            'contacts' => Contact::query()->orderBy('name')->get(['id', 'name']),
            'employees' => ProjectQuery::employeeOptions(),
            'kinds' => array_map(fn (ProjectKind $k) => ['value' => $k->value, 'label' => $k->label()], ProjectKind::cases()),
            'statuses' => array_map(fn (ProjectStatus $s) => ['value' => $s->value, 'label' => $s->label()], ProjectStatus::cases()),
            'openReviews' => AllocationReview::query()->whereNull('resolved_at')->count(),
            'can' => [
                'manage' => $user?->can('create', Project::class) ?? false,
                'allocate' => $user?->can('allocate', Project::class) ?? false,
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

    public function store(ProjectRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('create', Project::class);

        Project::create([
            ...$request->validated(),
            'organization_id' => $currentOrg->id(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', __('app.saved'));
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->update([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', $project->is_active),
        ]);

        return back()->with('success', __('app.saved'));
    }

    public function destroy(Project $project): RedirectResponse
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

        return back()->with('success', __('app.deleted'));
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }
}
