<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Api\Resources\VatRateResource;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Quote;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group VAT rates
 *
 * Read-only access to the VAT rates of the organisation, so invoice and
 * quote lines can name theirs by `vat_rate_id` or `vat_rate_code`.
 */
class VatRateApiController extends Controller
{
    /**
     * List VAT rates
     *
     * Returns all VAT rates, the default one first. Whoever may read
     * invoices, quotes or the accounting may read them.
     *
     * @queryParam active_only boolean Only return active rates. Example: true
     *
     * @response 200 scenario="Success" {"data":[{"id":"9c8f...","code":"NORMAL","name":"Standard Rate","rate":"8.10","is_default":true,"is_active":true},{"id":"9c90...","code":"EXEMPT","name":"Exempt","rate":"0.00","is_default":false,"is_active":true}]}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless(
            $user->can('viewAny', Invoice::class) || $user->can('viewAny', Quote::class) || $user->can('viewAny', VatRate::class),
            403,
        );

        return VatRateResource::collection(
            VatRate::query()
                ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
                ->orderByDesc('is_default')
                ->orderBy('code')
                ->get()
        );
    }
}
