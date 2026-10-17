<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Requests\AcquisitionRequest;
use App\Domains\Projects\Services\AcquisitionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API access to articles bought for people (spec 009, stage 4).
 */
class AcquisitionApiController extends Controller
{
    /**
     * List acquisitions
     *
     * The articles of a year with each person's charge and the totals.
     */
    public function index(Request $request, AcquisitionService $acquisitions, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];

        return response()->json(['data' => $acquisitions->overview($currentOrg->id(), $year)]);
    }

    /**
     * Create acquisition
     *
     * People are named by employee id with a share in percent adding up to
     * 100; the net amount follows from gross and VAT rate unless given.
     */
    public function store(AcquisitionRequest $request, AcquisitionService $acquisitions, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validated();
        $acquisition = $acquisitions->save(null, $currentOrg->id(), $validated, $validated['people']);

        return response()->json(['data' => [
            'id' => $acquisition->id,
            'item' => $acquisition->item,
            'net_amount' => $acquisition->net_amount,
            'charges' => AcquisitionService::charges($acquisition),
        ]], 201);
    }

    /**
     * List people
     *
     * Active employees an article can be assigned to.
     */
    public function people(): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        return response()->json(['data' => ProjectQuery::employeeOptions()->values()]);
    }
}
