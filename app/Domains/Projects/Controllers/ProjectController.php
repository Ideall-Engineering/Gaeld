<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\AllocationReview;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Requests\ProjectRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects and areas of an organization (spec 009, User Story 1).
 */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();

        return Inertia::render('Projects/Index', [
            'projects' => ProjectQuery::tree(),
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

        $used = LineAllocation::query()->where('cost_center_id', $project->id)->exists();

        if ($used) {
            return back()->withErrors(['project' => __('app.project_delete_used')]);
        }

        $project->delete();

        return back()->with('success', __('app.deleted'));
    }
}
