<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\SteeringItem;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Services\PlanActualService;
use App\Domains\Projects\Services\SteeringService;
use App\Domains\Reporting\Services\ExportReportService;
use App\Http\Controllers\Concerns\AnswersApiCalls;
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
 * Seen by everyone who manages jobs (spec 012); keys, blocks and plan items
 * are changed by owners only.
 */
class SteeringController extends Controller
{
    use AnswersApiCalls;

    public function index(Request $request, SteeringService $steering, PlanActualService $plans, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewSteering', Project::class);

        $year = $this->year($request);
        $canSteer = $request->user()?->can('steer', Project::class) ?? false;

        return Inertia::render('Projects/Steering', [
            'year' => $year,
            'canSteer' => $canSteer,
            'steering' => $steering->forYear($currentOrg->id(), $year),
            'employees' => ProjectQuery::employeeOptions(),
            // Spec 010: each cost block can be edited right here, by owners.
            'groups' => $plans->forYear($currentOrg->id(), $year, withKeys: $canSteer)['groups'],
            'blockOptions' => ProjectQuery::blockOptions(),
        ]);
    }

    /**
     * The general costs of the steering on their own page: the cost blocks,
     * who bears and who is credited with each.
     */
    public function generalCosts(Request $request, SteeringService $steering, PlanActualService $plans, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewSteering', Project::class);

        $year = $this->year($request);
        $canSteer = $request->user()?->can('steer', Project::class) ?? false;
        $data = $steering->forYear($currentOrg->id(), $year);

        $plan = $plans->forYear($currentOrg->id(), $year, withKeys: $canSteer);

        return Inertia::render('Projects/GeneralCosts', [
            'year' => $year,
            'canSteer' => $canSteer,
            // Blocks are added, changed and removed here (they left the jobs page).
            'canManage' => $request->user()?->can('create', Project::class) ?? false,
            'hasPreviousYear' => BudgetGroup::query()->where('year', $year - 1)->exists(),
            'elapsed' => $plan['elapsed'],
            'steering' => [
                'people' => array_map(fn (array $p) => ['employee_id' => $p['employee_id'], 'name' => $p['name']], $data['people']),
                'blocks' => $data['blocks'],
            ],
            'employees' => ProjectQuery::employeeOptions(),
            'groups' => $plan['groups'],
            'blockOptions' => ProjectQuery::blockOptions(),
            // The bookings of a block can be moved to jobs and people here.
            'allocationOptions' => $request->user()?->can('allocate', Project::class) ? [
                'projects' => ProjectQuery::tree(),
                'employees' => ProjectQuery::employeeOptions(),
            ] : null,
        ]);
    }

    /**
     * The steering of a year over the API (accountants and up).
     */
    public function indexApi(Request $request, SteeringService $steering, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('viewSteering', Project::class);

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

    public function destroyItem(SteeringItem $steeringItem): RedirectResponse|JsonResponse
    {
        $this->authorize('steer', Project::class);

        $steeringItem->delete();

        return $this->answer(__('app.deleted'));
    }

    public function export(Request $request, string $format, SteeringService $steering, CurrentOrganization $currentOrg, ExportReportService $exporter): HttpResponse
    {
        $this->authorize('viewSteering', Project::class);

        $year = $this->year($request);
        $data = $steering->forYear($currentOrg->id(), $year);
        $fields = ['contribution', 'effect', 'value', 'drawn', 'play', 'prospects', 'later', 'booked_salary'];

        return $exporter->export(
            $format,
            csvBuilder: function () use ($exporter, $data, $fields, $year) {
                $rows = array_map(fn (array $p) => [$p['name'], ...array_map(fn ($f) => $p[$f], $fields)], $data['people']);
                $rows[] = [__('app.total'), ...array_map(fn ($f) => $data['totals'][$f], $fields)];

                // Spec 012: the expenses still expected, below the people.
                $rows[] = [];
                $rows[] = [__('app.steering_expected_expenses').' '.$year];
                $rows[] = [__('app.date'), __('app.description'), __('app.source'), __('app.account'), __('app.amount'), __('app.steering_expected_gross')];
                foreach ($data['expected_expenses']['rows'] ?? [] as $row) {
                    $rows[] = [$row['date'] ?? '', self::expectedLabel($row), __('app.steering_expected_source_'.$row['source']), $row['account'] ?? '', $row['amount'], $row['gross']];
                }
                $rows[] = [__('app.total'), '', '', '', $data['expected_expenses']['total'] ?? '0.00', $data['expected_expenses']['cash_total'] ?? '0.00'];

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

    /**
     * The label of an expected expense as the page shows it.
     *
     * @param  array<string, mixed>  $row
     */
    public static function expectedLabel(array $row): string
    {
        return match ($row['source']) {
            'social' => __('app.steering_expected_social_label'),
            'block' => __('app.steering_expected_block_label', ['name' => $row['label']]),
            'vat' => __('app.steering_expected_vat_'.$row['label'], ['quarter' => $row['quarter'] ?? '']),
            default => (string) $row['label'],
        };
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
