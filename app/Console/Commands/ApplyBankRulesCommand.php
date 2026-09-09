<?php

namespace App\Console\Commands;

use App\Domains\Banking\Enums\BankRuleOutcome;
use App\Domains\Banking\Models\BankRuleApplication;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Banking\Services\BankRuleEngine;
use App\Domains\Organizations\Models\Organization;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Runs the rule engine over transactions that are already in the database.
 *
 * Imports compute their own suggestions, so this exists for the cases an import
 * hook cannot cover: statements imported before a rule existed, and rules
 * written or corrected after the fact. Re-running is harmless — the engine
 * skips anything that already carries an application.
 */
class ApplyBankRulesCommand extends Command
{
    protected $signature = 'gaeld:apply-bank-rules
                            {--organization= : Organization id (default: the only one, if there is exactly one)}
                            {--from= : Only transactions on or after this date (Y-m-d)}
                            {--to= : Only transactions on or before this date (Y-m-d)}
                            {--dry-run : Report what would be proposed without writing}';

    protected $description = 'Compute rule suggestions for bank transactions already imported';

    public function handle(BankRuleEngine $engine): int
    {
        if (FeatureFlag::disabled('rule_engine')) {
            $this->error('The rule engine is disabled (FEATURE_RULE_ENGINE).');

            return self::FAILURE;
        }

        $organization = $this->resolveOrganization();

        if (! $organization) {
            return self::FAILURE;
        }

        $query = BankTransaction::query()
            ->with('bankAccount')
            ->whereHas('bankAccount', fn ($q) => $q->where('organization_id', $organization->id))
            ->where('is_reconciled', false)
            ->whereDoesntHave('ruleApplication')
            ->orderBy('date');

        if ($from = $this->option('from')) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = $this->option('to')) {
            $query->whereDate('date', '<=', $to);
        }

        $transactions = $query->get();

        if ($transactions->isEmpty()) {
            $this->info('No open transactions without a proposal.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->report($transactions, $engine);
        }

        $applications = $engine->evaluateAll($transactions);

        $this->info(sprintf(
            '%d transactions examined, %d proposals created, %d left without a matching rule.',
            $transactions->count(),
            $applications->count(),
            $transactions->count() - $applications->count(),
        ));

        if ($engine->lastFailureCount() > 0) {
            $this->warn(sprintf(
                '%d transactions could not be processed — see the log.',
                $engine->lastFailureCount(),
            ));
        }

        $pending = BankRuleApplication::where('organization_id', $organization->id)
            ->where('outcome', BankRuleOutcome::Pending)
            ->count();

        if ($pending > 0) {
            $this->line("Waiting for review: {$pending}. See /banking/rule-review.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, BankTransaction>  $transactions
     */
    private function report($transactions, BankRuleEngine $engine): int
    {
        $rows = [];
        $matched = 0;

        foreach ($transactions as $transaction) {
            $rule = $engine->previewRuleFor($transaction);

            if ($rule) {
                $matched++;
            }

            $rows[] = [
                $transaction->date->toDateString(),
                mb_substr((string) ($transaction->creditor_name ?: $transaction->debtor_name ?: $transaction->description), 0, 30),
                (string) $transaction->amount,
                $rule?->name ?? '—',
                $rule?->account_code ?? '—',
                $rule?->tax_treatment->value ?? '—',
            ];
        }

        $this->table(['Datum', 'Gegenpartei', 'Betrag', 'Regel', 'Konto', 'MWST'], $rows);
        $this->info(sprintf(
            '[Probelauf] %d von %d Transaktionen träfen auf eine Regel (%d %%).',
            $matched,
            $transactions->count(),
            $transactions->count() > 0 ? (int) round($matched / $transactions->count() * 100) : 0,
        ));

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $id = $this->option('organization');

        if ($id) {
            $organization = Organization::find($id);

            if (! $organization) {
                $this->error("Organization {$id} not found.");

                return null;
            }

            return $organization;
        }

        $organizations = Organization::query()->limit(2)->get();

        if ($organizations->count() === 1) {
            return $organizations->first();
        }

        $this->error('Several organizations exist — pass --organization=<id>.');

        return null;
    }
}
