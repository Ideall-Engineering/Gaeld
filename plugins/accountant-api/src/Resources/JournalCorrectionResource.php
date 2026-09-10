<?php

namespace Plugins\AccountantApi\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No `@mixin`/import of the core `JournalCorrection` model: every field is
 * read through dynamic property access, which `JsonResource` proxies to the
 * wrapped object regardless of its class. Keeps this resource decoupled
 * from the core model per the module/core boundary (see CoreBridge).
 */
class JournalCorrectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'source' => $this->source?->value,
            'reason' => $this->reason,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'original' => $this->entryLink($this->original),
            'reversal' => $this->entryLink($this->reversal),
            'replacement' => $this->entryLink($this->replacement),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A compact, non-recursive reference — not a full JournalEntryResource,
     * to avoid three-deep nested line payloads on every correction read.
     *
     * @return array<string, mixed>|null
     */
    private function entryLink(mixed $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'id' => $entry->id,
            'reference' => $entry->reference,
            'date' => $entry->date?->toDateString(),
            'is_posted' => $entry->is_posted,
            'debit_total' => $entry->totalDebit(),
            'credit_total' => $entry->totalCredit(),
        ];
    }
}
