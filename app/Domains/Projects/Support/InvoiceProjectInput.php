<?php

namespace App\Domains\Projects\Support;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\InvoiceProject;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Services\InvoiceProjectService;
use App\Domains\Projects\Services\ProjectReportService;
use App\Domains\Projects\Services\SteeringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The project field of the invoice forms (spec 009, stage 2), kept out of
 * the invoicing requests and DTOs so those stay as they are.
 */
final class InvoiceProjectInput
{
    /**
     * The validated project of a request: an id, null to clear, or false
     * when the request says nothing or may not say anything about it.
     */
    public static function fromRequest(Request $request, ?Invoice $invoice = null): int|false|null
    {
        $org = app(CurrentOrganization::class);

        if (! $org->isBound()
            || ! ProjectsModule::enabledFor($org->get())
            || ! $request->exists('project_id')
            || ! ($request->user()?->can('allocate', Project::class) ?? false)) {
            return false;
        }

        $current = $invoice === null
            ? null
            : InvoiceProject::query()->where('invoice_id', $invoice->id)->value('cost_center_id');

        $validated = $request->validate([
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('cost_centers', 'id')
                    ->where('organization_id', $org->id())
                    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current ?? 0)),
            ],
        ]);

        return isset($validated['project_id']) ? (int) $validated['project_id'] : null;
    }

    public static function apply(Invoice $invoice, int|false|null $projectId, ?int $userId = null): void
    {
        if ($projectId === false) {
            return;
        }

        app(InvoiceProjectService::class)->assign($invoice, $projectId, $userId);
    }

    /**
     * Projects to offer on an invoice, with the customer each belongs to so
     * the form can preselect a customer's one open project; null where the
     * field is not shown at all.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function options(Request $request): ?array
    {
        $org = app(CurrentOrganization::class);

        if (! $org->isBound()
            || ! ProjectsModule::enabledFor($org->get())
            || ! ($request->user()?->can('allocate', Project::class) ?? false)) {
            return null;
        }

        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'depth' => $row['depth'],
            'is_active' => $row['is_active'],
            'contact_id' => $row['contact_id'],
            'kind' => $row['kind'],
            'parent_id' => $row['parent_id'] ?? null,
            'is_open' => $row['is_active']
                && $row['kind'] !== ProjectKind::Area->value
                && ! in_array($row['status'], [ProjectStatus::Done->value, ProjectStatus::Lost->value], true),
        ], ProjectQuery::tree());
    }

    /**
     * A new invoice started from a job (?project_id=): its customer, the job
     * and one line with what the job still brings beyond everything invoiced.
     *
     * @return array{customer_id: string, project_id: string, description: string, unit_price: string}|null
     */
    public static function prefill(Request $request): ?array
    {
        if (self::options($request) === null || ! $request->filled('project_id')) {
            return null;
        }

        $job = Project::query()->whereKey($request->integer('project_id'))->where('kind', '<>', ProjectKind::Area->value)->first();
        if ($job === null) {
            return null;
        }

        $steering = app(SteeringService::class);
        $ever = app(ProjectReportService::class)->totalsByProject($job->organization_id, '1900-01-01', '2999-12-31')[$job->id]['revenue'] ?? '0.00';

        return [
            'customer_id' => $job->contact_id === null ? '' : (string) $job->contact_id,
            'project_id' => (string) $job->id,
            'description' => $job->name,
            'unit_price' => $steering->restOf($job, $ever),
        ];
    }

    public static function currentFor(Invoice $invoice): ?int
    {
        return app(InvoiceProjectService::class)->projectIdFor($invoice);
    }
}
