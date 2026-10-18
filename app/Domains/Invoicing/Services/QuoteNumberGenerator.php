<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Models\Quote;

/**
 * Sequential quote numbers per organization and year: OF-YYYY-NNN.
 */
class QuoteNumberGenerator
{
    public const PREFIX = 'OF';

    public function next(string $organizationId, ?int $forYear = null): string
    {
        $fullPrefix = self::PREFIX.'-'.($forYear ?? now()->year).'-';
        $prefixLen = strlen($fullPrefix);

        $maxSequence = Quote::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('number', 'like', "{$fullPrefix}%")
            ->withTrashed()
            ->pluck('number')
            ->map(fn (string $number) => (int) substr($number, $prefixLen))
            ->max() ?? 0;

        return $fullPrefix.str_pad((string) ($maxSequence + 1), 3, '0', STR_PAD_LEFT);
    }
}
