<?php

namespace App\Console\Commands;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Banking\Enums\BankRuleAction;
use App\Domains\Banking\Enums\BankRuleDirection;
use App\Domains\Banking\Enums\BankRuleMatchField;
use App\Domains\Banking\Models\BankRule;
use App\Domains\Expenses\Enums\ExpenseTaxTreatment;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Console\Command;

/**
 * Installs the starter rule set for an organization.
 *
 * Every rule ships as `suggest`. None of them may post by itself, and the VAT
 * treatments encode the distinction that matters most here: a supplier billing
 * from abroad must never be booked with ordinary Swiss input tax just because
 * its name is familiar.
 *
 * Re-running is safe — existing rules are matched by name and updated in place,
 * so a corrected rule stays corrected unless --force is given.
 */
class SeedBankRulesCommand extends Command
{
    protected $signature = 'gaeld:seed-bank-rules
                            {--organization= : Organization id (default: the only one, if there is exactly one)}
                            {--force : Overwrite rules that already exist}
                            {--dry-run : Show what would happen without writing}';

    protected $description = 'Install the starter bank posting rules for an organization';

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        $rc = ExpenseTaxTreatment::ReverseCharge->value;
        $std = ExpenseTaxTreatment::Standard->value;
        $imp = ExpenseTaxTreatment::ImportTax->value;
        $none = ExpenseTaxTreatment::None->value;

        return [
            // Foreign digital services. No Swiss VAT on the invoice; acquisition
            // tax is owed and deducted in the same entry.
            ['Google Cloud', 'Google Cloud', '6530', $rc, 10, 'Auslandleistung — Bezugsteuer geschuldet und abziehbar'],
            ['OpenAI', 'OpenAI', '6530', $rc, 10, 'Auslandleistung — Bezugsteuer geschuldet und abziehbar'],
            ['Anthropic', 'Anthropic', '6530', $rc, 10, 'Auslandleistung — Bezugsteuer geschuldet und abziehbar'],
            ['Notion', 'Notion', '6530', $rc, 10, 'Auslandleistung — Bezugsteuer geschuldet und abziehbar'],
            ['Canva', 'Canva', '6530', $rc, 10, 'Als Abo auf 6530 vereinheitlicht; Auslandleistung — Bezugsteuer'],

            // Microsoft invoices Swiss VAT under some contracts and not under
            // others, so this one stays standard and asks to be looked at.
            ['Microsoft', 'Microsoft', '6530', $std, 10, 'MWST auf der Rechnung prüfen — je nach Vertrag mit oder ohne Schweizer MWST'],

            // Domestic services with Swiss VAT on the invoice.
            ['DHL', 'DHL', '6520', $std, 20, 'Schweizer MWST auf der Rechnung prüfen'],
            ['Post CH', 'Post CH', '6520', $std, 20, 'Schweizer MWST auf der Rechnung prüfen'],

            // Goods from abroad: the deductible tax is on the customs assessment,
            // never on the supplier invoice.
            ['Alibaba', 'Alibaba', '4000', $imp, 20, 'Einfuhrsteuer separat ab Veranlagungsverfügung der Zollverwaltung buchen'],
            ['AliExpress', 'AliExpress', '4000', $imp, 20, 'Einfuhrsteuer separat ab Veranlagungsverfügung der Zollverwaltung buchen'],

            // Exempt from VAT — no input tax to claim.
            ['AXA', 'AXA', '6300', $none, 20, 'Versicherungsprämien sind von der MWST ausgenommen — kein Vorsteuerabzug'],
        ];
    }

    /**
     * Bank charges arrive as free text in the description rather than as a
     * counterparty, so they are matched differently from the vendor rules.
     *
     * @return array<int, array<string, mixed>>
     */
    private function descriptionDefinitions(): array
    {
        $none = ExpenseTaxTreatment::None->value;

        return [
            ['Bankgebühren', 'gebühr', '6950', $none, 30, 'Bankgebühren sind von der MWST ausgenommen'],
            ['Bankspesen', 'spesen', '6950', $none, 30, 'Bankspesen sind von der MWST ausgenommen'],
        ];
    }

    public function handle(): int
    {
        $organization = $this->resolveOrganization();

        if (! $organization) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // Only Standard and ReverseCharge need a rate; the rest carry no VAT.
        $defaultRate = VatRate::where('organization_id', $organization->id)
            ->where('is_default', true)
            ->first()
            ?? VatRate::where('organization_id', $organization->id)->orderByDesc('rate')->first();

        if (! $defaultRate) {
            $this->error('No VAT rate configured for this organization.');

            return self::FAILURE;
        }

        $rows = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $missingAccounts = [];

        $all = [];
        foreach ($this->definitions() as $d) {
            $all[] = [...$d, BankRuleMatchField::Counterparty];
        }
        foreach ($this->descriptionDefinitions() as $d) {
            $all[] = [...$d, BankRuleMatchField::Description];
        }

        foreach ($all as [$name, $matchText, $accountCode, $treatment, $priority, $reason, $field]) {
            $accountExists = Account::where('organization_id', $organization->id)
                ->where('code', $accountCode)
                ->exists();

            if (! $accountExists) {
                $missingAccounts[] = "{$name} → {$accountCode}";

                continue;
            }

            $needsRate = in_array($treatment, [
                ExpenseTaxTreatment::Standard->value,
                ExpenseTaxTreatment::ReverseCharge->value,
            ], true);

            $attributes = [
                'match_text' => $matchText,
                'match_field' => $field->value,
                'direction' => BankRuleDirection::Debit->value,
                'account_code' => $accountCode,
                'tax_treatment' => $treatment,
                'vat_rate_id' => $needsRate ? $defaultRate->id : null,
                'priority' => $priority,
                'action' => BankRuleAction::Suggest->value,
                'is_active' => true,
                'reason' => $reason,
            ];

            $existing = BankRule::where('organization_id', $organization->id)
                ->where('name', $name)
                ->first();

            $state = match (true) {
                $existing && ! $force => 'vorhanden',
                $existing => 'aktualisiert',
                default => 'neu',
            };

            $rows[] = [$name, $matchText, $accountCode, $treatment, $priority, $state];

            if ($state === 'vorhanden') {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $state === 'neu' ? $created++ : $updated++;

                continue;
            }

            if ($existing) {
                $existing->update($attributes);
                $updated++;
            } else {
                BankRule::create([
                    'organization_id' => $organization->id,
                    'name' => $name,
                    ...$attributes,
                ]);
                $created++;
            }
        }

        $this->table(['Regel', 'Suchtext', 'Konto', 'MWST-Behandlung', 'Prio', 'Status'], $rows);

        if ($missingAccounts !== []) {
            $this->warn('Übersprungen, weil das Zielkonto fehlt: '.implode(', ', $missingAccounts));
        }

        $this->info(sprintf(
            '%s%d neu, %d aktualisiert, %d unverändert.',
            $dryRun ? '[Probelauf] ' : '',
            $created,
            $updated,
            $skipped,
        ));

        if ($skipped > 0 && ! $force) {
            $this->line('Bestehende Regeln bleiben unangetastet. Mit --force überschreiben.');
        }

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
