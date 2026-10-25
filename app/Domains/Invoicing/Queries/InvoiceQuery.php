<?php

namespace App\Domains\Invoicing\Queries;

use App\Domains\Invoicing\Enums\InvoiceListState;
use App\Domains\Invoicing\Models\Invoice;
use App\Support\QueryBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class InvoiceQuery
{
    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    public static function list(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $query = QueryBuilder::for(Invoice::with(['customer', 'lines.vatRate', 'payments'])->withSum('payments', 'amount'), $request)
            ->allowedSorts(['issue_date', 'due_date', 'total', 'number', 'status'], 'issue_date', 'desc')
            ->allowedFilters(['status', 'type'])
            ->searchable(['number', 'customer.name'])
            ->apply();

        if ($request->has('filter.state')) {
            InvoiceListState::apply($query, InvoiceListState::parse($request->input('filter.state')));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Invoices per group of the status column, within the chosen type.
     *
     * @return array<string, int>
     */
    public static function stateCounts(Request $request): array
    {
        $query = Invoice::query();
        if ($request->filled('filter.type')) {
            $query->where('type', $request->input('filter.type'));
        }

        return InvoiceListState::counts($query);
    }
}
