<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceInstallment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment plans of invoices (spec 013). The last rate always carries the
 * rest of the total; payments are not tied to a rate but fill the rates
 * in order of their due dates, and the invoice falls due with the first
 * rate not yet covered.
 */
class InstallmentPlanService
{
    public const MIN_RATES = 2;

    public const MAX_RATES = 12;

    /**
     * Replaces the plan of a draft. An empty list removes it; the amount
     * given for the last rate is ignored.
     *
     * @param  list<array{due_date: string, amount?: string|int|float|null}>  $rates
     *
     * @throws ValidationException when the plan breaks a rule of the spec
     */
    public function sync(Invoice $invoice, array $rates): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new InvalidInvoiceStateException(__('app.payment_plan_draft_only'));
        }

        $planned = $rates === [] ? [] : $this->plannedRates($invoice, array_values($rates));

        DB::transaction(function () use ($invoice, $planned): void {
            $invoice->installments()->delete();

            foreach ($planned as $index => $rate) {
                $invoice->installments()->create([
                    'organization_id' => $invoice->organization_id,
                    'position' => $index + 1,
                    'due_date' => $rate['due_date'],
                    'amount' => $rate['amount'],
                ]);
            }

            if ($planned !== []) {
                $invoice->update(['due_date' => $planned[0]['due_date']]);
            }
        });

        $invoice->unsetRelation('installments');
    }

    /**
     * The plan checked against the rules, with the rest on the last rate.
     *
     * @param  list<array{due_date: string, amount?: string|int|float|null}>  $rates
     * @return list<array{due_date: string, amount: string}>
     */
    public function plannedRates(Invoice $invoice, array $rates): array
    {
        $count = count($rates);
        if ($count < self::MIN_RATES || $count > self::MAX_RATES) {
            $this->refuse(__('app.payment_plan_count', ['min' => self::MIN_RATES, 'max' => self::MAX_RATES]));
        }

        $planned = [];
        $previous = null;
        $others = '0.00';

        foreach ($rates as $index => $rate) {
            $date = Carbon::parse($rate['due_date'])->startOfDay();
            $first = $previous === null;
            if (($first && $date->lt($invoice->issue_date->copy()->startOfDay())) || (! $first && $date->lte($previous))) {
                $this->refuse(__('app.payment_plan_dates'));
            }
            $previous = $date;

            $amount = null;
            if ($index < $count - 1) {
                $given = $rate['amount'] ?? null;
                if ($given === null || ! is_numeric($given) || ! Money::isPositive(Money::of($given))) {
                    $this->refuse(__('app.payment_plan_amounts'));
                }
                $amount = Money::of($given);
                $others = Money::add($others, $amount);
            }

            $planned[] = ['due_date' => $date->toDateString(), 'amount' => $amount];
        }

        $rest = Money::subtract(Money::of((string) $invoice->total), $others);
        if (! Money::isPositive($rest)) {
            $this->refuse(__('app.payment_plan_no_rest'));
        }
        $planned[$count - 1]['amount'] = $rest;

        /** @var list<array{due_date: string, amount: string}> $planned */
        return $planned;
    }

    /**
     * Each rate with what of it is paid and open, payments filling the
     * rates in order. Empty for an invoice without a plan.
     *
     * @return list<array{installment: InvoiceInstallment, paid: string, open: string, overdue: bool}>
     */
    public function states(Invoice $invoice): array
    {
        $remaining = Money::of($invoice->amountPaid());
        $today = now()->startOfDay();
        $states = [];

        $installments = $invoice->relationLoaded('installments') ? $invoice->installments : $invoice->installments()->get();

        foreach ($installments as $installment) {
            $amount = Money::of((string) $installment->amount);
            $paid = Money::compare($remaining, $amount) >= 0
                ? $amount
                : (Money::isPositive($remaining) ? $remaining : '0.00');
            $remaining = Money::subtract($remaining, $paid);
            $open = Money::subtract($amount, $paid);

            $states[] = [
                'installment' => $installment,
                'paid' => $paid,
                'open' => $open,
                'overdue' => Money::isPositive($open) && $installment->due_date->lt($today),
            ];
        }

        return $states;
    }

    /**
     * Moves the invoice's due date to the first rate not yet covered (the
     * last rate once all are) and an open invoice between sent and overdue
     * along with it. Leaves an invoice without a plan alone.
     */
    public function refresh(Invoice $invoice): void
    {
        $states = $this->states($invoice);
        if ($states === []) {
            return;
        }

        $next = collect($states)->first(fn (array $state) => Money::isPositive($state['open'])) ?? end($states);
        $dueDate = $next['installment']->due_date->copy();

        $changes = ['due_date' => $dueDate->toDateString()];
        if (in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true)) {
            $changes['status'] = $dueDate->lt(now()->startOfDay()) ? InvoiceStatus::Overdue : InvoiceStatus::Sent;
        }

        $invoice->update($changes);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['installments' => $message]);
    }
}
