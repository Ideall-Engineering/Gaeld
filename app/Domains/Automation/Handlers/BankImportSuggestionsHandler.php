<?php

namespace App\Domains\Automation\Handlers;

use App\Domains\Automation\Contracts\AutomationHandler;
use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Banking\Models\BankImport;
use App\Domains\Banking\Services\BankRuleEngine;
use App\Domains\Organizations\Enums\Permission;
use App\Support\FeatureFlag;

/**
 * After an import, work out what each new transaction probably is.
 *
 * Writes nothing but proposals, so it may run from the start — a proposal
 * nobody reads is harmless, an unnoticed booking is not.
 */
class BankImportSuggestionsHandler implements AutomationHandler
{
    public function __construct(private readonly BankRuleEngine $engine) {}

    public function key(): string
    {
        return 'bank_import_suggestions';
    }

    public function label(): string
    {
        return 'automation_bank_import_suggestions';
    }

    public function description(): string
    {
        return 'automation_bank_import_suggestions_desc';
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
        if (FeatureFlag::disabled('rule_engine')) {
            return AutomationResult::of(__('app.automation_rule_engine_off'));
        }

        $importId = (string) $context->payload('bank_import_id', '');

        $import = BankImport::withoutGlobalScope('organization')
            ->where('organization_id', $context->organization->id)
            ->find($importId);

        if (! $import) {
            return AutomationResult::of(__('app.automation_import_not_found'));
        }

        $applications = $this->engine->evaluateAll(
            $import->transactions()->with('bankAccount')->where('is_reconciled', false)->get()
        );

        $failed = $this->engine->lastFailureCount();

        if ($failed > 0) {
            throw new \RuntimeException(__('app.automation_suggestions_partially_failed', ['count' => $failed]));
        }

        return AutomationResult::of(
            __('app.automation_suggestions_created', ['count' => $applications->count()]),
            ['suggestions' => $applications->count(), 'bank_import_id' => $import->id],
        );
    }
}
