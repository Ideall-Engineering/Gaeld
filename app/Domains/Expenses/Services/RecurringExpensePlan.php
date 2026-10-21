<?php

namespace App\Domains\Expenses\Services;

use App\Domains\Accounting\Models\Account;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Support\Money;
use Carbon\Carbon;

/**
 * What the recurring expenses of an organisation add to the plan of a year,
 * per expense account: each net amount times the due days that fall in the
 * year, from the first due day up to the end date.
 *
 * A paused schedule without end date counts nowhere; one that ran out (the
 * job switches it off past its end date) still counts in the years it ran.
 */
class RecurringExpensePlan
{
    /**
     * @return array<int, list<array{id: int, uuid: string, label: string, amount: string, frequency: string, starts_on: string, end_date: ?string, count: int, yearly: string}>>
     */
    public function forYear(string $organizationId, int $year): array
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->startOfDay();

        $schedules = RecurringExpense::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('end_date'))
            ->orderBy('id')
            ->get();
        if ($schedules->isEmpty()) {
            return [];
        }

        $byCode = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('code', $schedules->pluck('expense_account_code')->filter()->unique()->all())
            ->pluck('id', 'code');

        $plan = [];
        foreach ($schedules as $schedule) {
            $accountId = $schedule->expense_account_id ?? $byCode[$schedule->expense_account_code] ?? null;
            if ($accountId === null) {
                continue;
            }

            $start = ($schedule->starts_on ?? $schedule->next_due_date)->copy()->startOfDay();
            $count = 0;
            for ($day = $start; $day->lessThanOrEqualTo($to); $day = $schedule->frequency->nextDate($day, $schedule->anchorDay())) {
                if ($schedule->end_date !== null && $day->greaterThan($schedule->end_date)) {
                    break;
                }
                if ($day->greaterThanOrEqualTo($from)) {
                    $count++;
                }
            }
            if ($count === 0) {
                continue;
            }

            $plan[(int) $accountId][] = [
                'id' => $schedule->id,
                'uuid' => $schedule->uuid,
                'label' => (string) ($schedule->description ?: ($schedule->vendor ?: $schedule->category)),
                'amount' => (string) $schedule->amount,
                'frequency' => $schedule->frequency->value,
                'starts_on' => $start->toDateString(),
                'end_date' => $schedule->end_date?->toDateString(),
                'count' => $count,
                'yearly' => Money::round(Money::multiply((string) $schedule->amount, (string) $count)),
            ];
        }

        return $plan;
    }

    /**
     * The yearly total of the given items.
     *
     * @param  list<array{yearly: string}>  $items
     */
    public static function total(array $items): string
    {
        return Money::round(array_reduce($items, fn (string $sum, array $item) => Money::add($sum, $item['yearly']), '0.00'));
    }
}
