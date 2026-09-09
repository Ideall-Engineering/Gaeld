<?php

namespace App\Domains\Banking\Enums;

/**
 * What a rule is allowed to do when it matches.
 *
 * Suggest is the only safe default. AutoApply is a privilege a rule earns after
 * its suggestions have been confirmed unchanged often enough to trust.
 */
enum BankRuleAction: string
{
    case Suggest = 'suggest';
    case AutoApply = 'auto_apply';

    public function writesWithoutConfirmation(): bool
    {
        return $this === self::AutoApply;
    }

    public function label(): string
    {
        return __('app.bank_rule_action_'.$this->value);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c): array => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
