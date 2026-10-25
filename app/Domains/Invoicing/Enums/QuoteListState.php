<?php

namespace App\Domains\Invoicing\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * The groups of the quote list's status column.
 *
 * A quote turned into an invoice counts as "invoiced" whatever its status,
 * so it leaves the open work once billed. Every quote falls into exactly one
 * group.
 */
enum QuoteListState: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Invoiced = 'invoiced';

    /** @return array<int, self> */
    public static function defaults(): array
    {
        return [self::Draft, self::Sent, self::Accepted];
    }

    /**
     * Narrow the query to the given groups; an empty list shows everything.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, self>  $states
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, array $states): Builder
    {
        if ($states === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($states) {
            foreach ($states as $state) {
                $q->orWhereRaw($state->condition());
            }
        });
    }

    /**
     * How many quotes of the query fall into each group, in one query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return array<string, int>
     */
    public static function counts(Builder $query): array
    {
        $base = $query->toBase();
        $base->columns = null;
        $base->orders = null;

        foreach (self::cases() as $state) {
            $base->selectRaw("SUM(CASE WHEN {$state->condition()} THEN 1 ELSE 0 END) AS {$state->column()}");
        }

        $row = (array) $base->first();

        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => (int) ($row[$s->column()] ?? 0)])->all();
    }

    /**
     * Read the groups from "draft,sent,…"; unknown names are ignored.
     *
     * @return array<int, self>
     */
    public static function parse(?string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $v) => self::tryFrom(trim($v)),
            explode(',', (string) $value),
        )));
    }

    public function label(): string
    {
        return __('app.quote_list_state_'.$this->value);
    }

    /** @return literal-string */
    private function condition(): string
    {
        return match ($this) {
            self::Draft => "(quotes.invoice_id IS NULL AND quotes.status = 'draft')",
            self::Sent => "(quotes.invoice_id IS NULL AND quotes.status = 'sent')",
            self::Accepted => "(quotes.invoice_id IS NULL AND quotes.status = 'accepted')",
            self::Declined => "(quotes.invoice_id IS NULL AND quotes.status = 'declined')",
            self::Invoiced => 'quotes.invoice_id IS NOT NULL',
        };
    }

    /** @return literal-string */
    private function column(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Sent => 'sent',
            self::Accepted => 'accepted',
            self::Declined => 'declined',
            self::Invoiced => 'invoiced',
        };
    }
}
