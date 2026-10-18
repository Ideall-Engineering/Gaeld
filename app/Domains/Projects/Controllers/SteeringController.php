<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\SteeringItem;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Services\PlanActualService;
use App\Domains\Projects\Services\SteeringService;
use App\Domains\Reporting\Services\ExportReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Contribution, drawings and what is left per person (spec 009, stage 5).
 * Owners only: it shows what each owner draws.
 */
class SteeringController extends Controller
{
    public function index(Request $request, SteeringService $steering, PlanActualService $plans, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('steer', Project::class);

        $year = $this->year($request);

        return Inertia::render('Projects/Steering', [
            'year' => $year,
            'steering' => $steering->forYear($currentOrg->id(), $year),
            'employees' => ProjectQuery::employeeOptions(),
            // Spec 010: each cost block can be edited right here.
            'groups' => $plans->forYear($currentOrg->id(), $year, withKeys: true)['groups'],
            'blockOptions' => ProjectQuery::blockOptions(),
        ]);
    }

    /**
     * The steering of a year over the API (owners only).
     */
    public function indexApi(Request $request, SteeringService $steering, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('steer', Project::class);

        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];

        return response()->json(['data' => $steering->forYear($currentOrg->id(), $year)]);
    }

    /**
     * Add a planned drawing over the API (owners only).
     */
    public function storeItemApi(Request $request, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('steer', Project::class);

        $validated = $this->validateItem($request, $currentOrg);
        $item = SteeringItem::create([...$validated, 'organization_id' => $currentOrg->id()]);

        return response()->json(['data' => $item->only('id', 'year', 'employee_id', 'label', 'amount')], 201);
    }

    public function storeItem(Request $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('steer', Project::class);

        $validated = $this->validateItem($request, $currentOrg);

        SteeringItem::create([...$validated, 'organization_id' => $currentOrg->id()]);

        return back()->with('success', __('app.saved'));
    }

    public function destroyItem(SteeringItem $steeringItem): RedirectResponse
    {
        $this->authorize('steer', Project::class);

        $steeringItem->delete();

        return back()->with('success', __('app.deleted'));
    }

    public function export(Request $request, string $format, SteeringService $steering, CurrentOrganization $currentOrg, ExportReportService $exporter): HttpResponse
    {
        $this->authorize('steer', Project::class);

        $year = $this->year($request);
        $data = $steering->forYear($currentOrg->id(), $year);
        $fields = ['contribution', 'effect', 'value', 'drawn', 'play', 'prospects', 'later', 'booked_salary'];

        return $exporter->export(
            $format,
            csvBuilder: function () use ($exporter, $data, $fields, $year) {
                $rows = array_map(fn (array $p) => [$p['name'], ...array_map(fn ($f) => $p[$f], $fields)], $data['people']);
                $rows[] = [__('app.total'), ...array_map(fn ($f) => $data['totals'][$f], $fields)];

                return $exporter->csv()->export(
                    [__('app.acquisition_person'), ...array_map(fn ($f) => __('app.steering_'.$f), $fields)],
                    $rows,
                    "steering-{$year}.csv",
                );
            },
            pdfBuilder: fn () => $exporter->pdf()->download('exports.steering', [
                'organization' => $currentOrg->get(),
                'data' => $data,
                'fields' => $fields,
            ], "steering-{$year}.pdf"),
        );
    }

    /** @return array<string, mixed> */
    private function validateItem(Request $request, CurrentOrganization $currentOrg): array
    {
        return $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'employee_id' => ['required', 'uuid', Rule::exists('employees', 'id')->where('organization_id', $currentOrg->id())],
            'label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'between:-99999999999.99,99999999999.99'],
        ]);
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }
}
