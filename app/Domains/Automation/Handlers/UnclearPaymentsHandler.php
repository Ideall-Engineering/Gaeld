<?php

namespace App\Domains\Automation\Handlers;

use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Organizations\Enums\Permission;

/**
 * Collects payments that neither a rule nor the QR reference could settle.
 *
 * The counterpart to the automations that resolve things: whatever they leave
 * behind has to be visible somewhere, or it silently ages into next year.
 */
class UnclearPaymentsHandler implements AutomationHandler
{
    /** Below this age a transaction is simply new, not stuck. */
    private const STALE_AFTER_DAYS = 7;

    public function key(): string
    {
        return 'unclear_payments_review';
    }

    public function label(): string
    {
        return 'automation_unclear_payments_review';
    }

    public function description(): string
    {
        return 'automation_unclear_payments_review_desc';
    }

    public function writes(): bool
    {
        return false;
    }

    public function permission(): Permission
    {
        return Permission::BankingReconcile;
    }

    public function run(AutomationContext $context): AutomationResult
    {
        $stale = BankTransaction::query()
            ->with('bankAccount')
            ->whereHas('bankAccount', fn ($q) => $q->where('organization_id', $context->organization->id))
            ->where('is_reconciled', false)
            ->whereDoesntHave('ruleApplication')
            ->where('date', '<=', now()->subDays(self::STALE_AFTER_DAYS)->toDateString())
            ->orderBy('date')
            ->get();

        $findings = $stale->take(50)->map(fn (BankTransaction $t): string => __('app.automation_unclear_payment', [
            'date' => $t->date->toDateString(),
            'counterparty' => (string) ($t->creditor_name ?: $t->debtor_name ?: $t->description ?: '—'),
            'amount' => (string) $t->amount,
        ]))->all();

        return AutomationResult::withFindings(
            __('app.automation_unclear_payments_found', ['count' => $stale->count()]),
            $findings,
            ['unclear' => $stale->count(), 'listed' => count($findings)],
        );
    }
}
