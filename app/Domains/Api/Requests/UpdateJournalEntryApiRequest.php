<?php

namespace App\Domains\Api\Requests;

use App\Domains\Accounting\Models\JournalEntry;

/**
 * Amends a posted journal entry in place.
 *
 * Accepts the same line formats as creating an entry (explicit debit and
 * credit, or the VAT-code shorthand), minus `status`: the entry stays
 * posted.
 */
class UpdateJournalEntryApiRequest extends StoreJournalEntryApiRequest
{
    /**
     * Only who may amend at all. Whether this entry is open for it is
     * answered by the action with a stable `entry_not_editable` code and the
     * entry's edit state, which tells a client far more than a bare 403.
     */
    public function authorize(): bool
    {
        $journalEntry = $this->route('journalEntry');

        return $journalEntry instanceof JournalEntry
            && ($this->user()?->can('attemptAmend', $journalEntry) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['status']);

        return [
            ...$rules,
            'reason' => ['nullable', 'string', 'max:1000'],
            'acknowledge_bank_amount_change' => ['sometimes', 'boolean'],
        ];
    }
}
