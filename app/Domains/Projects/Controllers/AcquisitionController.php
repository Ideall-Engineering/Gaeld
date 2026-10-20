<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\AcquisitionStatus;
use App\Domains\Projects\Models\Acquisition;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Requests\AcquisitionRequest;
use App\Domains\Projects\Services\AcquisitionService;
use App\Http\Controllers\Concerns\AnswersApiCalls;
use App\Http\Controllers\Controller;
use App\Support\CsvExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Material and devices assigned to people (spec 009, User Story 11).
 */
class AcquisitionController extends Controller
{
    use AnswersApiCalls;

    public function index(Request $request, AcquisitionService $acquisitions, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', Project::class);

        $year = $this->year($request);
        $filters = $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:'.implode(',', AcquisitionStatus::values())],
        ]);

        return Inertia::render('Projects/Acquisitions', [
            'year' => $year,
            'filters' => ['employee_id' => $filters['employee_id'] ?? '', 'status' => $filters['status'] ?? ''],
            'overview' => $acquisitions->overview($currentOrg->id(), $year, $filters['employee_id'] ?? null, $filters['status'] ?? null),
            'employees' => ProjectQuery::employeeOptions(),
            'defaultVatRate' => AcquisitionService::DEFAULT_VAT_RATE,
            'deadlineDays' => AcquisitionService::DEADLINE_DAYS,
            'can' => ['manage' => $request->user()?->can('create', Project::class) ?? false],
        ]);
    }

    public function store(AcquisitionRequest $request, AcquisitionService $acquisitions, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validated();
        $acquisitions->save(null, $currentOrg->id(), $validated, $validated['people']);

        return back()->with('success', __('app.saved'));
    }

    public function update(AcquisitionRequest $request, Acquisition $acquisition, AcquisitionService $acquisitions): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validated();
        $acquisitions->save($acquisition, $acquisition->organization_id, $validated, $validated['people']);

        return $this->answer(__('app.saved'), ['id' => $acquisition->id]);
    }

    public function destroy(Acquisition $acquisition): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Project::class);

        $acquisition->delete();

        return $this->answer(__('app.deleted'));
    }

    public function export(Request $request, AcquisitionService $acquisitions, CurrentOrganization $currentOrg, CsvExportService $csv): HttpResponse
    {
        $this->authorize('viewAny', Project::class);

        $year = $this->year($request);
        $data = $acquisitions->overview($currentOrg->id(), $year);

        $rows = array_map(fn (array $r) => [
            $r['purchased_on'], $r['item'], $r['category'], $r['supplier'],
            implode(', ', array_map(fn (array $p) => $p['name'].' '.rtrim(rtrim($p['share'], '0'), '.').'%', $r['people'])),
            $r['quantity'], $r['unit_price'], $r['gross_amount'], $r['vat_rate'], $r['net_amount'],
            __('app.acquisition_status_'.$r['status']), $r['return_until'], $r['warranty_until'], $r['journal_reference'], $r['notes'],
        ], $data['rows']);

        return $csv->export([
            __('app.date'), __('app.acquisition_item'), __('app.acquisition_category'), __('app.acquisition_supplier'),
            __('app.acquisition_people'), __('app.acquisition_quantity'), __('app.acquisition_unit_price'), __('app.acquisition_gross'),
            __('app.acquisition_vat_rate'), __('app.acquisition_net'), __('app.status'), __('app.acquisition_return_until'),
            __('app.acquisition_warranty_until'), __('app.journal_entry'), __('app.notes'),
        ], $rows, "acquisitions-{$year}.csv");
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }
}
