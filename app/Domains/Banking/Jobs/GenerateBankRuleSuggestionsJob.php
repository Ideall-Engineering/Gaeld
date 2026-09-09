<?php

namespace App\Domains\Banking\Jobs;

use App\Domains\Banking\Models\BankImport;
use App\Domains\Banking\Services\BankRuleEngine;
use App\Support\Exceptions\FeatureDisabledException;
use App\Support\FeatureFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Computes rule suggestions for the transactions of one import.
 *
 * Safe to run more than once: BankRuleEngine::evaluate() skips any transaction
 * that already carries an application, so a retry after a partial failure picks
 * up exactly where it stopped and never overwrites a decision a human already
 * made.
 */
class GenerateBankRuleSuggestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $bankImportId) {}

    public function handle(BankRuleEngine $engine): void
    {
        if (FeatureFlag::disabled('rule_engine')) {
            return;
        }

        $import = BankImport::withoutGlobalScope('organization')->find($this->bankImportId);

        if (! $import) {
            Log::warning('GenerateBankRuleSuggestionsJob: import vanished', [
                'bank_import_id' => $this->bankImportId,
            ]);

            return;
        }

        try {
            $applications = $engine->evaluateAll(
                $import->transactions()->with('bankAccount')->where('is_reconciled', false)->get()
            );
        } catch (FeatureDisabledException) {
            return;
        }

        Log::info('Bank rule suggestions generated', [
            'bank_import_id' => $import->id,
            'organization_id' => $import->organization_id,
            'suggestions' => $applications->count(),
        ]);

        if ($engine->lastFailureCount() > 0) {
            Log::warning('Bank rule suggestions incomplete', [
                'bank_import_id' => $import->id,
                'failed' => $engine->lastFailureCount(),
            ]);
        }
    }
}
