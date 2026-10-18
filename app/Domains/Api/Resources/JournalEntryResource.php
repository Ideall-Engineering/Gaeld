<?php

namespace App\Domains\Api\Resources;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JournalEntry */
class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'reference' => $this->reference,
            'description' => $this->description,
            // Where the original receipt is kept; a pointer, not part of the booking.
            'document_url' => $this->document_url,
            'status' => $this->is_posted ? 'posted' : 'draft',
            'is_posted' => $this->is_posted,
            'source' => $this->type ?? 'manual',
            // Send back as `If-Match` when amending or deleting a posted entry.
            'revision' => $this->revision,
            'edit_state' => $this->editStateService($request)->stateFor($this->resource)->value,
            // Descriptions can often still be sharpened where the rest is locked.
            'text_edit_state' => $this->editStateService($request)->textStateFor($this->resource)->value,
            'debit_total' => $this->totalDebit(),
            'credit_total' => $this->totalCredit(),
            // Insertion order — debit before credit, as the entry was written.
            // The relation itself stays unsorted: it is also used for SUM()
            // aggregates, and an ORDER BY breaks those in Postgres.
            'lines' => TransactionLineResource::collection(
                $this->whenLoaded('lines', fn () => $this->lines->sortBy('id')->values()),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * One service per request, so a collection shares its memoised period
     * and organization lookups instead of repeating them per entry.
     */
    private function editStateService(Request $request): JournalEntryEditStateService
    {
        $service = $request->attributes->get(JournalEntryEditStateService::class);

        if (! $service instanceof JournalEntryEditStateService) {
            $service = app(JournalEntryEditStateService::class);
            $request->attributes->set(JournalEntryEditStateService::class, $service);
        }

        return $service;
    }
}
