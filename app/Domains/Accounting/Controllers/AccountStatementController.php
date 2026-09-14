<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Enums\StatementBasis;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One account's movements — what a report drills into when somebody asks what a
 * figure like "Salaries 18'790.97" is made of.
 *
 * Two ways in, and the difference matters. The dashboard's month view asks for a
 * month on an operational basis; the profit and loss statement asks for its own
 * period on the ledger basis, because that is what its figures are built from.
 * The source therefore decides the basis, so the total shown here is the figure
 * that was clicked rather than a near miss.
 */
class AccountStatementController extends Controller
{
    /**
     * Where each source sends the reader back to, and on what basis it counts.
     *
     * Building the return link here rather than accepting one as a parameter
     * keeps this route from becoming an open redirect.
     *
     * @var array<string, StatementBasis>
     */
    private const SOURCES = [
        'dashboard' => StatementBasis::Operational,
        'pnl' => StatementBasis::Ledger,
    ];

    public function __invoke(Request $request, Account $account, LedgerQueryService $ledger): Response
    {
        $this->authorize('view', $account);

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::SOURCES))],
        ]);

        $source = $validated['source'] ?? 'dashboard';
        $basis = self::SOURCES[$source];

        if (isset($validated['from'], $validated['to'])) {
            return $this->renderPeriod($account, $ledger, $source, $basis, $validated['from'], $validated['to']);
        }

        // A month is always the dashboard's question, and it is always asked on
        // the operational basis — the year-end closing is not a month of trading.
        return $this->renderMonth($account, $ledger, $validated['month'] ?? null);
    }

    private function renderMonth(Account $account, LedgerQueryService $ledger, ?string $month): Response
    {
        // The leading '!' resets the day, so a month without one does not
        // inherit today's and overflow — '2026-02' on the 31st is not March.
        $start = $month !== null
            ? Carbon::createFromFormat('!Y-m', $month)
            : now()->startOfMonth();

        return $this->render($account, [
            'mode' => 'month',
            'month' => $start->format('Y-m'),
            'previousMonth' => $start->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $start->copy()->addMonthNoOverflow()->format('Y-m'),
            'period' => null,
            'basis' => StatementBasis::Operational->value,
            'backUrl' => "/dashboard?month={$start->format('Y-m')}#monthly-accounts",
            'backLabel' => __('app.back_to_month_view'),
            'statement' => $ledger->accountStatementForMonth($account, $start->year, $start->month),
        ]);
    }

    private function renderPeriod(
        Account $account,
        LedgerQueryService $ledger,
        string $source,
        StatementBasis $basis,
        string $from,
        string $to,
    ): Response {
        $start = Carbon::createFromFormat('!Y-m-d', $from);
        $end = Carbon::createFromFormat('!Y-m-d', $to);

        return $this->render($account, [
            'mode' => 'period',
            'month' => null,
            'previousMonth' => null,
            'nextMonth' => null,
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'basis' => $basis->value,
            'backUrl' => $this->backUrl($source, $start->toDateString(), $end->toDateString()),
            'backLabel' => $this->backLabel($source),
            'statement' => $ledger->accountStatementForPeriod($account, $start, $end, $basis),
        ]);
    }

    private function backUrl(string $source, string $from, string $to): string
    {
        return match ($source) {
            'pnl' => "/reports/profit-and-loss?from={$from}&to={$to}",
            default => '/dashboard?month='.Carbon::parse($from)->format('Y-m').'#monthly-accounts',
        };
    }

    private function backLabel(string $source): string
    {
        return match ($source) {
            'pnl' => __('app.back_to_profit_and_loss'),
            default => __('app.back_to_month_view'),
        };
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function render(Account $account, array $props): Response
    {
        return Inertia::render('Accounting/AccountStatement', [
            'account' => [
                'uuid' => $account->uuid,
                'code' => $account->code,
                'name' => $account->display_name,
                'type' => $account->type->value,
                'typeLabel' => $account->type->label(),
            ],
            ...$props,
        ]);
    }
}
