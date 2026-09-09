<?php

namespace App\Domains\Banking\Controllers;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Enums\BankRuleAction;
use App\Domains\Banking\Enums\BankRuleDirection;
use App\Domains\Banking\Enums\BankRuleMatchField;
use App\Domains\Banking\Models\BankRule;
use App\Domains\Banking\Requests\StoreBankRuleRequest;
use App\Domains\Banking\Services\BankRuleEngine;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Management of the organization's editable posting rules.
 */
class BankRuleController extends Controller
{
    public function __construct(private readonly BankRuleEngine $engine) {}

    public function index(): Response
    {
        $this->authorize('viewAny', BankRule::class);

        $rules = BankRule::query()
            ->with('vatRate:id,name,rate')
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        // The track record is what tells a rule that can be trusted from one
        // that merely has not been noticed yet, so it belongs in the list.
        $rules->each(fn (BankRule $rule) => $rule->setAttribute(
            'track_record', $this->engine->trackRecord($rule)
        ));

        return Inertia::render('Banking/Rules/Index', [
            'rules' => $rules,
            'options' => $this->options(),
            'autoApplyEnabled' => BankRuleEngine::autoApplyAllowed(),
            'canManage' => request()->user()->can('create', BankRule::class),
        ]);
    }

    public function store(StoreBankRuleRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('create', BankRule::class);

        BankRule::create([
            'organization_id' => $currentOrg->id(),
            ...$request->validated(),
        ]);

        return redirect()->route('banking.rules.index')->with('success', __('app.saved'));
    }

    public function update(StoreBankRuleRequest $request, BankRule $bankRule): RedirectResponse
    {
        $this->authorize('update', $bankRule);

        $bankRule->update($request->validated());

        return redirect()->route('banking.rules.index')->with('success', __('app.saved'));
    }

    public function destroy(BankRule $bankRule): RedirectResponse
    {
        $this->authorize('delete', $bankRule);

        // Applications keep their snapshot and lose only the back-reference,
        // so deleting a rule never erases the record of what it once proposed.
        $bankRule->delete();

        return redirect()->route('banking.rules.index')->with('success', __('app.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'accounts' => Account::query()
                ->whereIn('type', ['expense', 'revenue'])
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'vatRates' => VatRate::query()
                ->where('is_active', true)
                ->orderBy('rate')
                ->get(['id', 'name', 'rate']),
            'matchFields' => BankRuleMatchField::options(),
            'directions' => BankRuleDirection::options(),
            'actions' => BankRuleAction::options(),
            'taxTreatments' => ExpenseTaxTreatment::options(),
        ];
    }
}
