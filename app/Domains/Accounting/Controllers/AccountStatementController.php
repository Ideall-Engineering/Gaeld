<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One account's movements in one month — what the dashboard's month view drills
 * into when somebody asks what a figure like "Salaries 18'790.97" is made of.
 */
class AccountStatementController extends Controller
{
    public function __invoke(Request $request, Account $account, LedgerQueryService $ledger): Response
    {
        $this->authorize('view', $account);

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        // The leading '!' resets the day, so a month without one does not
        // inherit today's and overflow — '2026-02' on the 31st is not March.
        $month = isset($validated['month'])
            ? Carbon::createFromFormat('!Y-m', $validated['month'])
            : now()->startOfMonth();

        return Inertia::render('Accounting/AccountStatement', [
            'account' => [
                'uuid' => $account->uuid,
                'code' => $account->code,
                'name' => $account->display_name,
                'type' => $account->type->value,
                'typeLabel' => $account->type->label(),
            ],
            'month' => $month->format('Y-m'),
            'previousMonth' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
            'statement' => $ledger->accountStatementForMonth($account, $month->year, $month->month),
        ]);
    }
}
