<?php

namespace App\Domains\Banking\Enums;

/**
 * What became of a suggestion.
 *
 * Corrected is kept apart from Confirmed on purpose: a rule whose suggestions
 * are routinely corrected is a rule that is wrong, and that only shows up if the
 * two are counted separately.
 */
enum BankRuleOutcome: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Corrected = 'corrected';
    case Rejected = 'rejected';

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return __('app.bank_rule_outcome_'.$this->value);
    }
}
