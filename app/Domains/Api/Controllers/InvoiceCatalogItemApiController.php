<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Invoicing\Models\InvoiceCatalogItem;
use App\Domains\Invoicing\Queries\InvoiceCatalogItemQuery;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catalog of recurring line items offered in the invoice form.
 *
 * @group Invoices
 */
class InvoiceCatalogItemApiController extends Controller
{
    /**
     * List catalog items
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', InvoiceCatalogItem::class);

        return response()->json(['data' => InvoiceCatalogItemQuery::all()->map(fn (InvoiceCatalogItem $item) => $this->row($item))->values()]);
    }

    /**
     * Create catalog item
     *
     * Needs the right to change the organisation settings, as on the web.
     */
    public function store(Request $request, CurrentOrganization $currentOrg): JsonResponse
    {
        $this->authorize('update', $currentOrg->get());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_unit_price' => ['nullable', 'numeric', 'min:0'],
            'default_vat_rate_id' => ['nullable', 'uuid', Rule::exists('vat_rates', 'uuid')->where('organization_id', $currentOrg->id())],
        ]);

        $item = InvoiceCatalogItem::create([
            'organization_id' => $currentOrg->id(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'default_unit_price' => $validated['default_unit_price'] ?? null,
            'default_vat_rate_id' => isset($validated['default_vat_rate_id'])
                ? VatRate::where('uuid', $validated['default_vat_rate_id'])->value('id')
                : null,
            'sort_order' => (InvoiceCatalogItem::where('organization_id', $currentOrg->id())->max('sort_order') ?? 0) + 1,
        ]);

        return response()->json(['data' => $this->row($item)], 201);
    }

    /**
     * Delete catalog item
     *
     * @urlParam catalogItem string required The item UUID.
     */
    public function destroy(InvoiceCatalogItem $catalogItem, CurrentOrganization $currentOrg): JsonResponse
    {
        abort_unless($catalogItem->organization_id === $currentOrg->id(), 404);
        $this->authorize('update', $currentOrg->get());
        $catalogItem->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function row(InvoiceCatalogItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'default_unit_price' => $item->default_unit_price !== null ? (string) $item->default_unit_price : null,
            'default_vat_rate_id' => $item->default_vat_rate_id ? VatRate::whereKey($item->default_vat_rate_id)->value('uuid') : null,
        ];
    }
}
