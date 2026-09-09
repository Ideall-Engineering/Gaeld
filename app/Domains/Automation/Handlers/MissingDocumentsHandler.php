<?php

namespace App\Domains\Automation\Handlers;

use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Organizations\Enums\Permission;

/**
 * Finds expenses that cannot survive an audit as they stand.
 *
 * Two kinds of gap, both of which only hurt much later:
 *   - no receipt filed, so the deduction has nothing behind it;
 *   - import VAT declared but no customs assessment booked, so input tax that
 *     was flagged as recoverable never actually gets recovered.
 */
class MissingDocumentsHandler implements AutomationHandler
{
    public function key(): string
    {
        return 'missing_documents_report';
    }

    public function label(): string
    {
        return 'automation_missing_documents_report';
    }

    public function description(): string
    {
        return 'automation_missing_documents_report_desc';
    }

    public function writes(): bool
    {
        return false;
    }

    public function permission(): Permission
    {
        return Permission::ExpensesView;
    }

    public function run(AutomationContext $context): AutomationResult
    {
        $orgId = $context->organization->id;

        $withoutReceipt = Expense::where('organization_id', $orgId)
            ->whereNull('receipt_path')
            ->whereNull('archived_at')
            ->orderBy('date')
            ->get(['id', 'date', 'vendor', 'amount', 'description']);

        $withoutVatRate = Expense::where('organization_id', $orgId)
            ->whereNull('vat_rate_id')
            ->whereIn('tax_treatment', [
                ExpenseTaxTreatment::Standard->value,
                ExpenseTaxTreatment::ReverseCharge->value,
            ])
            ->orderBy('date')
            ->get(['id', 'date', 'vendor', 'amount']);

        $awaitingCustoms = Expense::where('organization_id', $orgId)
            ->where('tax_treatment', ExpenseTaxTreatment::ImportTax->value)
            ->whereNull('receipt_path')
            ->orderBy('date')
            ->get(['id', 'date', 'vendor', 'amount']);

        $findings = [];

        foreach ($withoutReceipt->take(30) as $expense) {
            $findings[] = __('app.automation_missing_receipt', [
                'date' => $expense->date->toDateString(),
                'vendor' => (string) ($expense->vendor ?: $expense->description ?: '—'),
                'amount' => (string) $expense->amount,
            ]);
        }

        foreach ($withoutVatRate->take(30) as $expense) {
            $findings[] = __('app.automation_missing_vat_rate', [
                'date' => $expense->date->toDateString(),
                'vendor' => (string) ($expense->vendor ?: '—'),
            ]);
        }

        foreach ($awaitingCustoms->take(30) as $expense) {
            $findings[] = __('app.automation_missing_customs_document', [
                'date' => $expense->date->toDateString(),
                'vendor' => (string) ($expense->vendor ?: '—'),
            ]);
        }

        return AutomationResult::withFindings(
            __('app.automation_missing_documents_found', [
                'receipts' => $withoutReceipt->count(),
                'vat' => $withoutVatRate->count(),
                'customs' => $awaitingCustoms->count(),
            ]),
            $findings,
            [
                'without_receipt' => $withoutReceipt->count(),
                'without_vat_rate' => $withoutVatRate->count(),
                'awaiting_customs_document' => $awaitingCustoms->count(),
            ],
        );
    }
}
