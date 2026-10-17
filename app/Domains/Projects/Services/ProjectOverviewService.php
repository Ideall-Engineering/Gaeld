<?php

namespace App\Domains\Projects\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the project overview (spec 009, stage 2): what each
 * project was expected to bring, what the books say it brought and cost,
 * what was invoiced, received and is still open, and where its deadline
 * stands. Areas show the sum of everything below them.
 */
class ProjectOverviewService
{
    /** Days before the target date from which a project counts as critical. */
    public const CRITICAL_DAYS = 14;

    /** An invoice open by no more than this counts as paid (rounding). */
    private const PAID_TOLERANCE = '1.00';

    public function __construct(
        private ProjectReportService $reports,
    ) {}

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     by_status: array<int, array<string, mixed>>,
     *     by_area: array<int, array<string, mixed>>,
     *     totals: array<string, string>,
     * }
     */
    public function overview(string $organizationId, string $from, string $to, ?Carbon $today = null): array
    {
        $today ??= now()->startOfDay();

        $projects = Project::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->with(['contact:id,name', 'responsible:id,first_name,last_name'])
            ->orderBy('code')
            ->get();

        $booked = $this->reports->totalsByProject($organizationId, $from, $to);
        $invoiced = $this->invoiceFigures($organizationId, $from, $to);

        $own = [];
        foreach ($projects as $project) {
            $own[$project->id] = [
                'expected' => $project->expected_amount !== null ? (string) $project->expected_amount : '0.00',
                'revenue' => $booked[$project->id]['revenue'] ?? '0.00',
                'expenses' => $booked[$project->id]['expenses'] ?? '0.00',
                'invoiced' => $invoiced[$project->id]['invoiced'] ?? '0.00',
                'received' => $invoiced[$project->id]['received'] ?? '0.00',
                'open' => $invoiced[$project->id]['open'] ?? '0.00',
                'invoice_count' => $invoiced[$project->id]['count'] ?? 0,
            ];
        }

        $children = $projects->groupBy(fn (Project $p) => $p->parent_id ?? 0);
        $rollup = function (int $id, int $depth = 0) use (&$rollup, $children, $own): array {
            $sum = $own[$id];
            if ($depth > 32) {
                return $sum;
            }
            foreach ($children->get($id, collect()) as $child) {
                $sub = $rollup($child->id, $depth + 1);
                foreach (['expected', 'revenue', 'expenses', 'invoiced', 'received', 'open'] as $field) {
                    $sum[$field] = Money::add($sum[$field], $sub[$field]);
                }
                $sum['invoice_count'] += $sub['invoice_count'];
            }

            return $sum;
        };

        $byId = $projects->keyBy('id');
        $rows = [];
        foreach ($projects as $project) {
            $figures = $project->kind === ProjectKind::Area ? $rollup($project->id) : $own[$project->id];

            $rows[] = [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'parent_id' => $project->parent_id,
                'area' => $this->areaOf($project, $byId),
                'kind' => $project->kind->value,
                'status' => $project->status->value,
                'is_active' => $project->is_active,
                'customer' => $project->contact?->name,
                'responsible' => $project->responsible !== null
                    ? trim("{$project->responsible->first_name} {$project->responsible->last_name}")
                    : null,
                'target_date' => $project->target_date?->toDateString(),
                'billing_month' => $project->billing_month?->format('Y-m'),
                'deadline' => $this->deadline($project, $today),
                'expected' => $figures['expected'],
                'revenue' => $figures['revenue'],
                'expenses' => $figures['expenses'],
                'net' => Money::subtract($figures['revenue'], $figures['expenses']),
                'invoiced' => $figures['invoiced'],
                'received' => $figures['received'],
                'open' => $figures['open'],
                'payment' => $this->paymentState($figures['invoice_count'], $figures['open'], $figures['received']),
            ];
        }

        // Sums count every amount once: an area row adds up what is below
        // it, so only what is allocated to the area itself counts here.
        $leaves = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== ProjectKind::Area->value) {
                $leaves[] = $row;

                continue;
            }
            $figures = $own[$row['id']];
            $leaves[] = [
                ...$row,
                ...array_intersect_key($figures, array_flip(['expected', 'revenue', 'expenses', 'invoiced', 'received', 'open'])),
                'net' => Money::subtract($figures['revenue'], $figures['expenses']),
            ];
        }

        return [
            'rows' => $rows,
            'by_status' => $this->sumBy($leaves, 'status', array_map(fn (ProjectStatus $s) => $s->value, ProjectStatus::cases())),
            'by_area' => $this->sumBy($leaves, 'area'),
            'totals' => $this->sum($leaves),
        ];
    }

    /**
     * Invoiced, received and open per project, from the invoices linked to
     * it and issued in the period; credit notes count against their invoice.
     *
     * @return array<int, array{invoiced: string, received: string, open: string, count: int}>
     */
    private function invoiceFigures(string $organizationId, string $from, string $to): array
    {
        $invoices = DB::table('invoice_projects')
            ->join('invoices', 'invoices.id', '=', 'invoice_projects.invoice_id')
            ->where('invoice_projects.organization_id', $organizationId)
            ->whereNull('invoices.deleted_at')
            ->whereNotIn('invoices.status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->whereBetween('invoices.issue_date', [$from, $to])
            ->get(['invoice_projects.cost_center_id', 'invoices.id', 'invoices.type', 'invoices.total', 'invoices.related_invoice_id']);

        $paid = DB::table('invoice_payments')
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) AS paid')
            ->pluck('paid', 'invoice_id');

        $credited = $invoices
            ->where('type', InvoiceType::CreditNote->value)
            ->groupBy('related_invoice_id')
            // Credit notes carry a negative total; what they credit is its amount.
            ->map(fn ($notes) => $notes->reduce(fn (string $sum, $note) => Money::add($sum, Money::absoluteAmount((string) $note->total)), '0.00'));

        $figures = [];
        foreach ($invoices as $invoice) {
            $projectId = (int) $invoice->cost_center_id;
            $figures[$projectId] ??= ['invoiced' => '0.00', 'received' => '0.00', 'open' => '0.00', 'count' => 0];

            if ($invoice->type === InvoiceType::CreditNote->value) {
                $figures[$projectId]['invoiced'] = Money::subtract($figures[$projectId]['invoiced'], Money::absoluteAmount((string) $invoice->total));

                continue;
            }

            $received = Money::of((string) ($paid[$invoice->id] ?? '0'));
            $open = Money::subtract(
                Money::subtract((string) $invoice->total, $received),
                (string) ($credited[$invoice->id] ?? '0.00'),
            );

            $figures[$projectId]['invoiced'] = Money::add($figures[$projectId]['invoiced'], (string) $invoice->total);
            $figures[$projectId]['received'] = Money::add($figures[$projectId]['received'], $received);
            $figures[$projectId]['open'] = Money::add($figures[$projectId]['open'], Money::isPositive($open) ? $open : '0.00');
            $figures[$projectId]['count']++;
        }

        return $figures;
    }

    /** done, none, overdue, critical or on_track, as the spreadsheet had it. */
    private function deadline(Project $project, Carbon $today): string
    {
        if (in_array($project->status, [ProjectStatus::Done, ProjectStatus::Lost], true)) {
            return 'done';
        }

        if ($project->target_date === null) {
            return 'none';
        }

        $days = $today->diffInDays($project->target_date->copy()->startOfDay(), false);

        return match (true) {
            $days < 0 => 'overdue',
            $days <= self::CRITICAL_DAYS => 'critical',
            default => 'on_track',
        };
    }

    /** paid, partial or open; null when nothing was invoiced. */
    private function paymentState(int $invoiceCount, string $open, string $received): ?string
    {
        if ($invoiceCount === 0) {
            return null;
        }

        if (Money::compare($open, self::PAID_TOLERANCE) <= 0) {
            return 'paid';
        }

        return Money::isPositive($received) ? 'partial' : 'open';
    }

    /**
     * Code and name of the top-most area above a project, or of the project
     * itself when it is an area without a parent.
     *
     * @param  Collection<int, Project>  $byId
     */
    private function areaOf(Project $project, $byId): ?string
    {
        $area = null;
        $cursor = $project;

        for ($depth = 0; $cursor !== null && $depth < 32; $depth++) {
            if ($cursor->kind === ProjectKind::Area) {
                $area = $cursor;
            }
            $cursor = $cursor->parent_id !== null ? $byId->get($cursor->parent_id) : null;
        }

        return $area === null ? null : "{$area->code} {$area->name}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $order  keys to list first, in this order
     * @return array<int, array<string, mixed>>
     */
    private function sumBy(array $rows, string $key, array $order = []): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) ($row[$key] ?? '')][] = $row;
        }

        $keys = array_keys($groups);
        usort($keys, function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib) ?: strcmp($a, $b);
        });

        return array_map(fn (string $group) => [
            'key' => $group === '' ? null : $group,
            'count' => count($groups[$group]),
            ...$this->sum($groups[$group]),
        ], $keys);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function sum(array $rows): array
    {
        $sum = ['expected' => '0.00', 'revenue' => '0.00', 'expenses' => '0.00', 'net' => '0.00', 'invoiced' => '0.00', 'received' => '0.00', 'open' => '0.00'];

        foreach ($rows as $row) {
            foreach (array_keys($sum) as $field) {
                $sum[$field] = Money::add($sum[$field], (string) $row[$field]);
            }
        }

        return $sum;
    }
}
