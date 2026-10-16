<?php

namespace App\Domains\Accounting\Rules;

use App\Domains\Accounting\Services\JournalEntryEditStateService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Keeps hand-entered references out of the prefixes the system uses to
 * recognise its own entries.
 *
 * A manual entry named `REV-VAT-SETTLEMENT-…` would otherwise count as the
 * reversal of that settlement and unlock a settled VAT quarter, and one
 * named `OPENING-…` or `DISP-…` would pass for an opening or an asset
 * disposal. A reference an entry already carries may stay, so a plain
 * reversal draft remains editable.
 */
class NotSystemReference implements ValidationRule
{
    public function __construct(
        private ?string $currentReference = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || $value === $this->currentReference) {
            return;
        }

        foreach (JournalEntryEditStateService::SYSTEM_REFERENCE_PREFIXES as $prefix) {
            if (stripos($value, $prefix) === 0) {
                $fail(__('app.journal_reference_reserved_prefix', ['prefix' => $prefix]));

                return;
            }
        }
    }
}
