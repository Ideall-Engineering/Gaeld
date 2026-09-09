<?php

namespace App\Domains\Automation\Handlers;

use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Banking\Enums\BankRuleOutcome;
use App\Domains\Banking\Models\BankRuleApplication;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Models\Organization;

/**
 * Checks whether a fiscal year is in a state where a tax declaration can be
 * trusted, before anybody finalizes one.
 *
 * The declaration itself aggregates whatever the ledger happens to contain, so
 * it cannot tell a complete year from an unfinished one. This can: it names the
 * things that would quietly distort the figures — payments nobody has decided
 * on, proposals still waiting, purchases whose VAT treatment was never settled.
 *
 * Reports only.
 */
class TaxDeclarationReadinessHandler implements AutomationHandler
{
    public function key(): string
    {
        return 'tax_declaration_readiness';
    }

    public function label(): string
    {
        return 'automation_tax_declaration_readiness';
    }

    public function description(): string
    {
        return 'automation_tax_declaration_readiness_desc';
    }

    public function writes(): bool
    {
        return false;
    }

    public function permission(): Permission
    {
        return Permission::AccountingView;
    }

    public function run(AutomationContext $context): AutomationResult
    {
        $organization = $context->organization;

        $year = $this->resolveYear($organization, $context->payload('fiscal_year'));

        if (! $year) {
            return AutomationResult::of(__('app.automation_readiness_no_fiscal_year'));
        }

        $from = $year->start_date->toDateString();
        $to = $year->end_date->toDateString();

        $unreconciled = BankTransaction::query()
            ->whereHas('bankAccount', fn ($q) => $q->where('organization_id', $organization->id))
            ->where('is_reconciled', false)
            ->whereBetween('date', [$from, $to])
            ->count();

        $pendingProposals = BankRuleApplication::where('organization_id', $organization->id)
            ->where('outcome', BankRuleOutcome::Pending)
            ->whereHas('transaction', fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->count();

        $expensesWithoutTreatment = Expense::where('organization_id', $organization->id)
            ->whereBetween('date', [$from, $to])
            ->where('tax_treatment', ExpenseTaxTreatment::Standard->value)
            ->whereNull('vat_rate_id')
            ->count();

        $awaitingCustoms = Expense::where('organization_id', $organization->id)
            ->whereBetween('date', [$from, $to])
            ->where('tax_treatment', ExpenseTaxTreatment::ImportTax->value)
            ->whereNull('receipt_path')
            ->count();

        $findings = [];

        if ($unreconciled > 0) {
            $findings[] = __('app.automation_readiness_unreconciled', ['count' => $unreconciled]);
        }

        if ($pendingProposals > 0) {
            $findings[] = __('app.automation_readiness_pending_proposals', ['count' => $pendingProposals]);
        }

        if ($expensesWithoutTreatment > 0) {
            $findings[] = __('app.automation_readiness_expenses_without_vat', ['count' => $expensesWithoutTreatment]);
        }

        if ($awaitingCustoms > 0) {
            $findings[] = __('app.automation_readiness_awaiting_customs', ['count' => $awaitingCustoms]);
        }

        $message = $findings === []
            ? __('app.automation_readiness_clear', ['year' => $year->name])
            : __('app.automation_readiness_blocked', ['year' => $year->name, 'count' => count($findings)]);

        return AutomationResult::withFindings($message, $findings, [
            'fiscal_year' => $year->name,
            'period' => ['from' => $from, 'to' => $to],
            'unreconciled_transactions' => $unreconciled,
            'pending_proposals' => $pendingProposals,
            'expenses_without_vat_rate' => $expensesWithoutTreatment,
            'awaiting_customs_document' => $awaitingCustoms,
            'ready' => $findings === [],
        ]);
    }

    private function resolveYear(Organization $organization, mixed $requested): ?FiscalYear
    {
        $query = FiscalYear::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id);

        if (is_string($requested) && $requested !== '') {
            return (clone $query)->where('name', $requested)->first()
                ?? $query->orderByDesc('start_date')->first();
        }

        // Default to the year that contains today, falling back to the latest.
        return (clone $query)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->first()
            ?? $query->orderByDesc('start_date')->first();
    }
}
