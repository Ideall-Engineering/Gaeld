<?php

namespace App\Domains\Invoicing\Queries;

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
        return QueryBuilder::for(Quote::with(['customer', 'invoice:id,number']), $request)
            ->allowedSorts(['issue_date', 'valid_until', 'total', 'number', 'status'], 'issue_date', 'desc')
            ->allowedFilters(['status'])
            ->searchable(['number', 'title', 'customer.name'])
            ->apply()
            ->paginate($perPage)
            ->withQueryString();
    }

    public static function fileName(Quote $quote): string
    {
        return 'offerte-'.Str::slug($quote->number).'.pdf';
    }
}
