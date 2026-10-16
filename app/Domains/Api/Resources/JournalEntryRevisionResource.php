<?php

namespace App\Domains\Api\Resources;

use App\Domains\Accounting\Models\JournalEntryRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JournalEntryRevision */
class JournalEntryRevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_entry_id' => $this->journal_entry_id,
            'revision' => $this->revision,
            'kind' => $this->kind->value,
            'reference' => $this->reference,
            'reason' => $this->reason,
            'source' => $this->source->value,
            'user_id' => $this->user_id,
            'recorded_at' => $this->created_at->toIso8601String(),
            // The complete state the entry had before this change.
            'snapshot' => $this->snapshot,
        ];
    }
}
