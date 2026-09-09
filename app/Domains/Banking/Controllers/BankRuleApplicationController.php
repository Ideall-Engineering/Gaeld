<?php

namespace App\Domains\Banking\Controllers;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Enums\BankRuleOutcome;
use App\Domains\Banking\Models\BankRuleApplication;
use App\Domains\Banking\Requests\DecideBankRuleApplicationRequest;
use App\Domains\Banking\Services\BankRuleEngine;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Organizations\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review list: proposals waiting for a human, and the decisions already made.
 */
class BankRuleApplicationController extends Controller
{
    public function __construct(private readonly BankRuleEngine $engine) {}

    public function index(): Response
    {
        $this->authorize('viewAny', BankRuleApplication::class);

        $pending = BankRuleApplication::query()
            ->with(['rule:id,name', 'transaction:id,date,description,amount,type,creditor_name,debtor_name', 'suggestedVatRate:id,name,rate'])
            ->where('outcome', BankRuleOutcome::Pending)
            ->orderByDesc('confidence')
            ->get();

        $decided = BankRuleApplication::query()
            ->with(['rule:id,name', 'transaction:id,date,description,amount,type,creditor_name,debtor_name', 'decidedBy:id,name'])
            ->where('outcome', '!=', BankRuleOutcome::Pending)
            ->orderByDesc('decided_at')
            ->limit(100)
            ->get();

        return Inertia::render('Banking/Rules/Review', [
            'pending' => $pending,
            'decided' => $decided,
            'options' => [
                'accounts' => Account::query()
                    ->whereIn('type', ['expense', 'revenue'])
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name']),
                'vatRates' => VatRate::query()->where('is_active', true)->orderBy('rate')->get(['id', 'name', 'rate']),
                'taxTreatments' => ExpenseTaxTreatment::options(),
            ],
            'canDecide' => request()->user()->can('viewAny', BankRuleApplication::class)
                && request()->user()->hasPermissionTo(Permission::BankingReconcile),
        ]);
    }

    public function decide(
        DecideBankRuleApplicationRequest $request,
        BankRuleApplication $bankRuleApplication,
    ): RedirectResponse {
        $this->authorize('decide', $bankRuleApplication);

        abort_if(
            $bankRuleApplication->outcome->isDecided(),
            409,
            __('app.bank_rule_application_already_decided'),
        );

        $validated = $request->validated();

        $this->engine->decide(
            $bankRuleApplication,
            $request->user(),
            accountCode: $validated['account_code'] ?? null,
            taxTreatment: $validated['tax_treatment'] ?? null,
            vatRateId: isset($validated['vat_rate_id']) ? (int) $validated['vat_rate_id'] : null,
            rejected: (bool) ($validated['rejected'] ?? false),
        );

        return back()->with('success', __('app.saved'));
    }
}
