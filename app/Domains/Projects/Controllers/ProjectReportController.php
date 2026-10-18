<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Services\ProjectReportService;
use App\Domains\Projects\Services\SteeringService;
use App\Domains\Reporting\Services\ExportReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Revenue, expenses and contribution per project (spec 009, User Story 5).
 */
class ProjectReportController extends Controller
{
    /**
     * The page of a job or group (spec 010, US4): figures, who works on it
     * and every allocated line.
     */
    public function show(Request $request, Project $project, ProjectReportService $reports, SteeringService $steering): Response
    {
        $this->authorize('view', $project);

        [$from, $to] = $this->period($request);
        $report = $reports->forProject($project, $from, $to);
        $user = $request->user();
        $canSteer = $user?->can('steer', Project::class) ?? false;

        $project->load(['contact:id,name', 'parent:id,code,name']);
        $value = $steering->valueOf($project, ['revenue' => $report['total_revenue'], 'expenses' => $report['total_expenses']], (int) substr($from, 0, 4));

        return Inertia::render('Projects/Report', [
            'project' => [
                ...collect(ProjectQuery::tree(withShares: $canSteer))->firstWhere('id', $project->id),
                'parent' => $project->parent === null ? null : ['id' => $project->parent->id, 'code' => $project->parent->code, 'name' => $project->parent->name],
            ],
            'report' => $report,
            'value' => $value,
            'split' => $canSteer && $project->kind !== ProjectKind::Area ? $steering->splitOf($project, $value) : [],
            'filters' => ['from' => $from, 'to' => $to],
            'options' => [
                'projects' => ProjectQuery::tree(),
                'contacts' => Contact::query()->orderBy('name')->get(['id', 'name']),
                'employees' => ProjectQuery::employeeOptions(),
                'kinds' => array_map(fn (ProjectKind $k) => ['value' => $k->value, 'label' => $k->label()], ProjectKind::cases()),
                'statuses' => array_map(fn (ProjectStatus $s) => ['value' => $s->value, 'label' => $s->label()], ProjectStatus::cases()),
            ],
            'can' => [
                'manage' => $user?->can('create', Project::class) ?? false,
                'allocate' => $user?->can('allocate', Project::class) ?? false,
                'steer' => $canSteer,
            ],
        ]);
    }

    public function export(
        Request $request,
        Project $project,
        string $format,
        ProjectReportService $reports,
        ExportReportService $exporter,
        CurrentOrganization $currentOrg,
    ): HttpResponse {
        $this->authorize('view', $project);

        [$from, $to] = $this->period($request);
        $report = $reports->forProject($project, $from, $to);
        $filename = "project-report-{$project->code}-{$from}-{$to}";

        return $exporter->export(
            $format,
            csvBuilder: function () use ($exporter, $report, $filename) {
                $rows = [['', '--- '.__('exports.project_report.revenue').' ---', '']];
                foreach ($report['revenue'] as $account) {
                    $rows[] = [$account['code'], $account['name'], $account['amount']];
                }
                $rows[] = ['', __('exports.project_report.total_revenue'), $report['total_revenue']];
                $rows[] = ['', '--- '.__('exports.project_report.expenses').' ---', ''];
                foreach ($report['expenses'] as $account) {
                    $rows[] = [$account['code'], $account['name'], $account['amount']];
                }
                $rows[] = ['', __('exports.project_report.total_expenses'), $report['total_expenses']];
                $rows[] = ['', __('exports.project_report.contribution'), $report['contribution']];

                return $exporter->csv()->export(
                    [__('exports.common.account'), '', __('exports.common.amount')],
                    $rows,
                    "{$filename}.csv",
                );
            },
            pdfBuilder: fn () => $exporter->pdf()->download('exports.project-report', [
                'organization' => $currentOrg->get(),
                'project' => $project,
                'period' => ['from' => $from, 'to' => $to],
                'report' => $report,
            ], "{$filename}.pdf"),
        );
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            $validated['from'] ?? now()->startOfYear()->toDateString(),
            $validated['to'] ?? now()->endOfYear()->toDateString(),
        ];
    }
}
