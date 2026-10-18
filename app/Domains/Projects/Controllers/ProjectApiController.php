<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectShare;
use App\Domains\Projects\Requests\ProjectApiRequest;
use App\Domains\Projects\Resources\ProjectResource;
use App\Domains\Projects\Services\ProjectReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * JSON API access to projects (spec 009, User Story 6).
 */
class ProjectApiController extends Controller
{
    /**
     * List projects
     *
     * Returns all projects and areas of the organisation, ordered by code.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->with(['parent:id,code', 'contact:id,uuid'])
            ->when($request->input('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('code')
            ->get();

        return ProjectResource::collection($projects);
    }

    /**
     * Show project
     *
     * @urlParam project integer required The project id. Example: 12
     */
    public function show(Project $project, CurrentOrganization $currentOrg): ProjectResource
    {
        $this->ensureOrganization($project, $currentOrg);
        $this->authorize('view', $project);

        return new ProjectResource($project->load(['parent:id,code', 'contact:id,uuid']));
    }

    /**
     * Create project
     */
    public function store(ProjectApiRequest $request, CurrentOrganization $currentOrg): JsonResponse
    {
        $project = Project::create([
            ...$request->projectAttributes(),
            'organization_id' => $currentOrg->id(),
        ]);

        return (new ProjectResource($project->fresh(['parent:id,code', 'contact:id,uuid'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update project
     *
     * @urlParam project integer required The project id. Example: 12
     */
    public function update(ProjectApiRequest $request, Project $project): ProjectResource
    {
        $project->update($request->projectAttributes());

        return new ProjectResource($project->fresh(['parent:id,code', 'contact:id,uuid']));
    }

    /**
     * Set project shares
     *
     * How the project's value is shared among people in steering, in
     * percent adding up to 100; an empty list clears it. Owners only.
     *
     * @urlParam project integer required The project id. Example: 12
     */
    public function shares(Request $request, Project $project, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->ensureOrganization($project, $currentOrg);
        $this->authorize('steer', Project::class);

        $validated = $request->validate([
            'shares' => ['present', 'array', 'max:10'],
            'shares.*.employee_id' => ['required', 'uuid', 'distinct', Rule::exists('employees', 'id')->where('organization_id', $currentOrg->id())],
            'shares.*.share' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);
        $total = array_sum(array_map(fn (array $s) => (float) $s['share'], $validated['shares']));
        if ($validated['shares'] !== [] && abs($total - 100) > 0.0001) {
            return response()->json(['message' => __('app.acquisition_shares_total'), 'code' => 'shares_total'], 422);
        }

        DB::transaction(function () use ($project, $validated): void {
            ProjectShare::query()->where('cost_center_id', $project->id)->delete();
            foreach ($validated['shares'] as $share) {
                ProjectShare::create(['organization_id' => $project->organization_id, 'cost_center_id' => $project->id, ...$share]);
            }
        });

        return response()->json(['data' => ProjectShare::query()->where('cost_center_id', $project->id)->get(['employee_id', 'share'])]);
    }

    /**
     * Project report
     *
     * Revenue, expenses and contribution of the project and everything below
     * it, from line allocations, without year-end closing entries.
     *
     * @urlParam project integer required The project id. Example: 12
     */
    public function report(Request $request, Project $project, ProjectReportService $reports, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->ensureOrganization($project, $currentOrg);
        $this->authorize('view', $project);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => [
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            'from' => $validated['from'],
            'to' => $validated['to'],
            // The line list belongs to the job page; the API keeps its shape.
            ...collect($reports->forProject($project, $validated['from'], $validated['to']))->except('lines')->all(),
        ]]);
    }

    /**
     * Route model binding runs before the API organization is known, so a
     * project of another organization would resolve; answer as if it did
     * not exist.
     */
    private function ensureOrganization(Project $project, CurrentOrganization $currentOrg): void
    {
        abort_unless($project->organization_id === $currentOrg->id(), 404);
    }
}
