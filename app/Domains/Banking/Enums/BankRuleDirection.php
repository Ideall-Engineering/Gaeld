<?php

namespace App\Domains\Banking\Enums;

/**
 * Which transaction direction a rule applies to.
 *
 * Wider than BankTransactionType because a rule may legitimately ignore the
 * direction — a counterparty that both bills and refunds, for instance.
 */
enum BankRuleDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
    case Any = 'any';

    public function matches(BankTransactionType $type): bool
    {
        return match ($this) {
            self::Any => true,
            self::Debit => $type === BankTransactionType::Debit,
            self::Credit => $type === BankTransactionType::Credit,
        };
    }

    public function label(): string
    {
        return __('app.bank_rule_direction_'.$this->value);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c): array => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
