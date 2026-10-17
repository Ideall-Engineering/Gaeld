<?php

namespace App\Domains\Projects\Services;

use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\AcquisitionStatus;
use App\Domains\Projects\Models\Acquisition;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Articles bought for people and what they charge each person (spec 009,
 * stage 4). The ledger is only read through the optional entry link.
 */
class AcquisitionService
{
    /** Days ahead in which a return deadline is flagged. */
    public const DEADLINE_DAYS = 14;

    /** Default Swiss standard rate for articles bought with VAT. */
    public const DEFAULT_VAT_RATE = '8.10';

    /**
     * Net amount the company bears: with VAT, the input tax it reclaims is
     * taken off; without, net equals gross.
     */
    public static function netFor(string $gross, ?string $vatRate): string
    {
        if ($vatRate === null || bccomp(Money::of($vatRate), '0', 2) === 0) {
            return Money::round($gross);
        }

        return Money::round(bcdiv(bcmul(Money::of($gross), '100', 8), bcadd('100', Money::of($vatRate), 8), 8));
    }

    /**
     * Create or change an article with its people.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{employee_id: string, share: string|int|float}>  $people
     */
    public function save(?Acquisition $acquisition, string $organizationId, array $attributes, array $people): Acquisition
    {
        $quantity = (string) ($attributes['quantity'] ?? '1');
        $gross = Money::round(bcmul(Money::of($quantity), Money::of((string) $attributes['unit_price']), 4));
        $vatRate = isset($attributes['vat_rate']) && $attributes['vat_rate'] !== '' ? (string) $attributes['vat_rate'] : null;
        $net = isset($attributes['net_amount']) && $attributes['net_amount'] !== ''
            ? Money::round((string) $attributes['net_amount'])
            : self::netFor($gross, $vatRate);

        return DB::transaction(function () use ($acquisition, $organizationId, $attributes, $people, $quantity, $gross, $vatRate, $net): Acquisition {
            $values = [
                'item' => $attributes['item'],
                'category' => $attributes['category'] ?? null,
                'supplier' => $attributes['supplier'] ?? null,
                'purchased_on' => $attributes['purchased_on'],
                'quantity' => $quantity,
                'unit_price' => $attributes['unit_price'],
                'gross_amount' => $gross,
                'vat_rate' => $vatRate,
                'net_amount' => $net,
                'status' => $attributes['status'] ?? AcquisitionStatus::Delivered->value,
                'return_until' => $attributes['return_until'] ?? null,
                'warranty_until' => $attributes['warranty_until'] ?? null,
                'journal_entry_id' => $attributes['journal_entry_id'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ];

            if ($acquisition === null) {
                $acquisition = Acquisition::create(['organization_id' => $organizationId, ...$values]);
            } else {
                $acquisition->update($values);
            }

            $acquisition->people()->sync(collect($people)->mapWithKeys(fn (array $p) => [
                $p['employee_id'] => ['share' => (string) $p['share']],
            ])->all());

            return $acquisition->load('people');
        });
    }

    /**
     * Charge of each person for one article, rounding on the last share;
     * nothing for a returned article.
     *
     * @return array<string, string> employee id => amount
     */
    public static function charges(Acquisition $acquisition): array
    {
        if ($acquisition->status === AcquisitionStatus::Returned) {
            return [];
        }

        $people = $acquisition->people->sortBy('id')->values();
        $parts = LineAllocationService::splitAmount(
            (string) $acquisition->net_amount,
            $people->map(fn (Employee $e) => (string) data_get($e, 'pivot.share'))->all(),
        );

        $charges = [];
        foreach ($people as $index => $employee) {
            $charges[$employee->id] = $parts[$index];
        }

        return $charges;
    }

    /**
     * Net charged per person for articles bought in the period.
     *
     * @return array<string, string> employee id => amount
     */
    public function chargesByPerson(string $organizationId, string $from, string $to): array
    {
        $totals = [];
        $articles = Acquisition::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereBetween('purchased_on', [$from, $to])
            ->with('people')
            ->get();

        foreach ($articles as $article) {
            foreach (self::charges($article) as $employeeId => $amount) {
                $totals[$employeeId] = Money::add($totals[$employeeId] ?? '0.00', $amount);
            }
        }

        return $totals;
    }

    /**
     * The articles of a year as the list shows them, with the totals per
     * person and the return deadlines that are due soon.
     *
     * @return array{rows: array<int, array<string, mixed>>, people: array<int, array<string, mixed>>, totals: array<string, string>}
     */
    public function overview(string $organizationId, int $year, ?string $employeeId = null, ?string $status = null, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $articles = Acquisition::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereYear('purchased_on', $year)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($employeeId, fn ($q) => $q->whereHas('people', fn ($p) => $p->where('employees.id', $employeeId)))
            ->with(['people', 'journalEntry:id,reference'])
            ->orderBy('purchased_on')
            ->orderBy('id')
            ->get();

        $perPerson = [];
        $totals = ['gross' => '0.00', 'net' => '0.00'];
        $rows = [];

        foreach ($articles as $article) {
            $charges = self::charges($article);
            foreach ($charges as $id => $amount) {
                $perPerson[$id] = Money::add($perPerson[$id] ?? '0.00', $amount);
            }
            if ($article->status !== AcquisitionStatus::Returned) {
                $totals['gross'] = Money::add($totals['gross'], (string) $article->gross_amount);
                $totals['net'] = Money::add($totals['net'], (string) $article->net_amount);
            }

            $returnDue = $article->status !== AcquisitionStatus::Returned
                && $article->return_until !== null
                && $article->return_until->greaterThanOrEqualTo($today)
                && $today->diffInDays($article->return_until) <= self::DEADLINE_DAYS;

            $rows[] = [
                'id' => $article->id,
                'item' => $article->item,
                'category' => $article->category,
                'supplier' => $article->supplier,
                'purchased_on' => $article->purchased_on->toDateString(),
                'quantity' => $article->quantity,
                'unit_price' => $article->unit_price,
                'gross_amount' => $article->gross_amount,
                'vat_rate' => $article->vat_rate,
                'net_amount' => $article->net_amount,
                'status' => $article->status->value,
                'return_until' => $article->return_until?->toDateString(),
                'warranty_until' => $article->warranty_until?->toDateString(),
                'return_due' => $returnDue,
                'journal_entry_id' => $article->journal_entry_id,
                'journal_reference' => $article->journalEntry?->reference,
                'notes' => $article->notes,
                'people' => $article->people->sortBy('id')->values()->map(fn (Employee $e) => [
                    'employee_id' => $e->id,
                    'name' => trim("{$e->first_name} {$e->last_name}"),
                    'share' => (string) data_get($e, 'pivot.share'),
                    'charge' => $charges[$e->id] ?? '0.00',
                ])->all(),
            ];
        }

        $names = Employee::withoutGlobalScopes()->withTrashed()->whereIn('id', array_keys($perPerson))->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $people = [];
        foreach ($perPerson as $id => $amount) {
            $people[] = ['employee_id' => $id, 'name' => trim(($names[$id]->first_name ?? '').' '.($names[$id]->last_name ?? '')), 'charge' => $amount];
        }
        usort($people, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return ['rows' => $rows, 'people' => $people, 'totals' => $totals];
    }
}
