<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\ProjectReportService;
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
    public function show(Request $request, Project $project, ProjectReportService $reports): Response
    {
        $this->authorize('view', $project);

        [$from, $to] = $this->period($request);

        return Inertia::render('Projects/Report', [
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            'report' => $reports->forProject($project, $from, $to),
            'filters' => ['from' => $from, 'to' => $to],
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
