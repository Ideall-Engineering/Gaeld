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

    public static function currentFor(Invoice $invoice): ?int
    {
        return app(InvoiceProjectService::class)->projectIdFor($invoice);
    }
}
