<?php

namespace App\Domains\Invoicing\Queries;

use App\Domains\Invoicing\Enums\QuoteListState;
use App\Domains\Invoicing\Models\Quote;
use App\Support\QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Read models for quote lists.
 */
class QuoteQuery
{
    /** @return LengthAwarePaginator<int, Quote> */
    public static function list(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $query = QueryBuilder::for(Quote::with(['customer', 'invoice:id,number']), $request)
            ->allowedSorts(['issue_date', 'valid_until', 'total', 'number', 'status'], 'issue_date', 'desc')
            ->allowedFilters(['status'])
            ->searchable(['number', 'title', 'customer.name'])
            ->apply();

        if ($request->has('filter.state')) {
            QuoteListState::apply($query, QuoteListState::parse($request->input('filter.state')));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Quotes per group of the status column.
     *
     * @return array<string, int>
     */
    public static function stateCounts(): array
    {
        return QuoteListState::counts(Quote::query());
    }

    public static function fileName(Quote $quote): string
    {
        return 'offerte-'.Str::slug($quote->number).'.pdf';
    }
}
