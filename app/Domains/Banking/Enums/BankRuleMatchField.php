<?php

namespace App\Domains\Banking\Enums;

use App\Domains\Banking\Models\BankTransaction;

/**
 * Which part of a transaction a rule's search text is compared against.
 *
 * Counterparty resolves to the creditor on outgoing and the debtor on incoming
 * payments, so a single rule works in both directions without the author having
 * to know which field a CAMT file happened to populate.
 */
enum BankRuleMatchField: string
{
    case Any = 'any';
    case Description = 'description';
    case Counterparty = 'counterparty';
    case Reference = 'reference';

    /** @return string[] The haystack values this field exposes for a transaction. */
    public function haystack(BankTransaction $transaction): array
    {
        $description = (string) $transaction->description;
        $counterparty = trim((string) $transaction->creditor_name.' '.(string) $transaction->debtor_name);
        $reference = trim((string) $transaction->reference.' '.(string) $transaction->structured_reference);

        return match ($this) {
            self::Description => [$description],
            self::Counterparty => [$counterparty],
            self::Reference => [$reference],
            self::Any => [$description, $counterparty, $reference],
        };
    }

    public function label(): string
    {
        return __('app.bank_rule_match_field_'.$this->value);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c): array => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
