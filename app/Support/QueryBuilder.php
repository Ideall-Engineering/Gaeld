<?php

namespace App\Support;

use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Scout\Searchable;

/**
 * Shared infrastructure for building filterable, sortable, and searchable
 * Eloquent queries from HTTP request parameters.
 *
 * Lives in App\Support (not a Domain) because it is a generic query utility
 * with no domain-specific logic — it is consumed across all domains.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
class QueryBuilder
{
    /** @var Builder<TModel> */
    private Builder $query;

    private Request $request;

    /** @var array<int, string> */
    private array $allowedSorts = [];

    /** @var array<int, string> */
    private array $allowedFilters = [];

    /** @var array<int, string> */
    private array $searchColumns = [];

    /** @var array<int, string> */
    private array $numericSearchColumns = [];

    private string $defaultSort = 'created_at';

    private string $defaultDirection = 'desc';

    /** @param Builder<TModel> $query */
    public function __construct(Builder $query, Request $request)
    {
        $this->query = $query;
        $this->request = $request;
    }

    /**
     * @template TFor of Model
     *
     * @param  Builder<TFor>  $query
     * @return static<TFor>
     */
    public static function for(Builder $query, Request $request): static
    {
        return new static($query, $request);
    }

    /**
     * Define which columns can be sorted by.
     *
     * @param  array<string>  $columns
     */
    public function allowedSorts(array $columns, string $default = 'created_at', string $defaultDirection = 'desc'): static
    {
        $this->allowedSorts = $columns;
        $this->defaultSort = $default;
        $this->defaultDirection = $defaultDirection;

        return $this;
    }

    /**
     * Define which columns can be filtered (exact match).
     *
     * @param  array<string>  $columns
     */
    public function allowedFilters(array $columns): static
    {
        $this->allowedFilters = $columns;

        return $this;
    }

    /**
     * Define which columns are searched via LIKE.
     *
     * @param  array<string>  $columns
     */
    public function searchable(array $columns): static
    {
        $this->searchColumns = $columns;

        return $this;
    }

    /**
     * Define which numeric columns are searched by exact value.
     *
     * A LIKE over an amount would make "250" match 1'250.00, which is noise
     * rather than a hit, so an amount is matched outright. The term is
     * stripped of thousands separators first, so that a figure copied off the
     * screen as "1'250.00" still finds the row storing 1250.00.
     *
     * @param  array<string>  $columns
     */
    public function searchableNumeric(array $columns): static
    {
        $this->numericSearchColumns = $columns;

        return $this;
    }

    /**
     * Apply sorting, filtering, search from request and return the builder.
     *
     * @return Builder<TModel>
     */
    public function apply(): Builder
    {
        $this->applyFilters();
        $this->applySearch();
        $this->applySorting();

        return $this->query;
    }

    private function applyFilters(): void
    {
        foreach ($this->allowedFilters as $filter) {
            $value = $this->request->input("filter.$filter");

            if ($value !== null && $value !== '') {
                $this->query->where($filter, $value);
            }
        }
    }

    private function applySearch(): void
    {
        $search = $this->request->input('search');

        if (empty($search) || (empty($this->searchColumns) && empty($this->numericSearchColumns))) {
            return;
        }

        $search = trim($search);
        $model = $this->query->getModel();

        // Use MeiliSearch when available and the model is searchable
        if (config('scout.driver') === 'meilisearch' && in_array(Searchable::class, class_uses_recursive($model))) {
            $this->applyMeiliSearch($search, $model);

            return;
        }

        $this->applyDatabaseSearch($search);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function applyMeiliSearch(string $search, $model): void
    {
        $scoutQuery = $model::search($search);

        // Tenant isolation: filter by current organization
        $currentOrg = app(CurrentOrganization::class);
        if ($currentOrg->isBound()) {
            $scoutQuery->where('organization_id', $currentOrg->id());
        }

        $ids = $scoutQuery->keys()->all();

        if (empty($ids)) {
            // No results — force empty result set
            $this->query->whereRaw('1 = 0');

            return;
        }

        $table = $model->getTable();
        $this->query->whereIn("{$table}.id", $ids);
    }

    private function applyDatabaseSearch(string $search): void
    {
        $likeOperator = $this->likeOperator();
        $numericSearch = $this->normalizeNumericSearch($search);

        $this->query->where(function (Builder $q) use ($search, $numericSearch, $likeOperator) {
            foreach ($this->searchColumns as $column) {
                $this->orWhereColumnMatches($q, $column, function (Builder $b, string $field) use ($search, $likeOperator) {
                    $b->where($b->qualifyColumn($field), $likeOperator, "%{$search}%");
                });
            }

            if ($numericSearch === null) {
                return;
            }

            foreach ($this->numericSearchColumns as $column) {
                $this->orWhereColumnMatches($q, $column, function (Builder $b, string $field) use ($numericSearch) {
                    $b->where($b->qualifyColumn($field), '=', $numericSearch);
                });
            }
        });
    }

    /**
     * Add one OR-branch for a search column, resolving a dotted path such as
     * `lines.account.code` into a whereHas() on `lines.account` matching `code`.
     *
     * @param  Builder<covariant Model>  $query
     * @param  \Closure(Builder<covariant Model>, string): void  $match
     */
    private function orWhereColumnMatches(Builder $query, string $column, \Closure $match): void
    {
        $separator = strrpos($column, '.');

        if ($separator === false) {
            $query->orWhere(function (Builder $q) use ($match, $column) {
                $match($q, $column);
            });

            return;
        }

        $relation = substr($column, 0, $separator);
        $field = substr($column, $separator + 1);

        $query->orWhereHas($relation, function (Builder $q) use ($match, $field) {
            $match($q, $field);
        });
    }

    /**
     * Reduce a search term to the plain decimal form used in the database, or
     * null when the term is not an amount at all and no numeric column should
     * be consulted.
     *
     * Accepts the Swiss apostrophe grouping ("1'250.50") and stray spaces so
     * that a figure copied straight off the screen still matches.
     */
    private function normalizeNumericSearch(string $search): ?string
    {
        $normalized = str_replace(["'", "\u{2019}", ' ', "\u{00A0}"], '', $search);

        return preg_match('/^\d+(\.\d{1,2})?$/', $normalized) === 1 ? $normalized : null;
    }

    private function applySorting(): void
    {
        $sort = $this->request->input('sort', $this->defaultSort);
        $direction = $this->request->input('direction', $this->defaultDirection);

        // Validate sort column against allowed list; reset direction to default if column is rejected
        if (! in_array($sort, $this->allowedSorts, true)) {
            $sort = $this->defaultSort;
            $direction = $this->defaultDirection;
        }

        // Validate direction
        $normalized = strtolower((string) $direction);
        $direction = in_array($normalized, ['asc', 'desc'], true)
            ? $normalized
            : (strtolower($this->defaultDirection) === 'desc' ? 'desc' : 'asc');

        $this->query->orderBy($sort, $direction);
    }

    /**
     * Use case-insensitive LIKE: ilike for PostgreSQL, like for others.
     */
    private function likeOperator(): string
    {
        $connection = $this->query->getQuery()->getConnection();

        // getConnection() is typed to the interface, which does not declare
        // getDriverName(); narrow to the concrete class before calling it
        // rather than assuming every ConnectionInterface implementation has it.
        if ($connection instanceof Connection && $connection->getDriverName() === 'pgsql') {
            return 'ilike';
        }

        return 'like';
    }
}
