<?php

namespace App\Domains\Invoicing\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * The groups of the invoice list's status column.
 *
 * They split the stored status further: an open invoice with a payment is
 * "partially paid", an open one past its due date "overdue". Every invoice
 * falls into exactly one group, so the counts add up to the whole list.
 */
enum InvoiceListState: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Overdue = 'overdue';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /** @return array<int, self> */
    public static function defaults(): array
    {
        return [self::Draft, self::Sent, self::Overdue, self::Partial, self::Paid];
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
                $q->orWhereRaw($state->condition(), $state->bindings());
            }
        });
    }

    /**
     * How many invoices of the query fall into each group, in one query.
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
            $base->selectRaw("SUM(CASE WHEN {$state->condition()} THEN 1 ELSE 0 END) AS {$state->column()}", $state->bindings());
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
        return __('app.invoice_list_state_'.$this->value);
    }

    /** @return literal-string */
    private function condition(): string
    {
        $paid = '(SELECT COALESCE(SUM(invoice_payments.amount), 0) FROM invoice_payments WHERE invoice_payments.invoice_id = invoices.id)';
        $open = "invoices.status IN ('sent', 'overdue')";
        $pastDue = 'invoices.due_date IS NOT NULL AND invoices.due_date < ?';

        return match ($this) {
            self::Draft => "invoices.status = 'draft'",
            self::Sent => "({$open} AND {$paid} = 0 AND NOT ({$pastDue}))",
            self::Overdue => "({$open} AND {$paid} = 0 AND {$pastDue})",
            self::Partial => "({$open} AND {$paid} <> 0)",
            self::Paid => "invoices.status = 'paid'",
            self::Cancelled => "invoices.status = 'cancelled'",
        };
    }

    /** @return literal-string */
    private function column(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Sent => 'sent',
            self::Overdue => 'overdue',
            self::Partial => 'partial',
            self::Paid => 'paid',
            self::Cancelled => 'cancelled',
        };
    }

    /** @return array<int, string> */
    private function bindings(): array
    {
        return in_array($this, [self::Sent, self::Overdue], true) ? [now()->toDateString()] : [];
    }
}
