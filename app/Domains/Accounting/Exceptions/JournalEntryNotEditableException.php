<?php

namespace App\Domains\Accounting\Exceptions;

use App\Domains\Accounting\Enums\JournalEntryEditState;
use App\Support\Exceptions\DomainException;

/**
 * Thrown when a posted journal entry, or the date it would be moved to,
 * is not open for an in-place amendment or deletion.
 */
class JournalEntryNotEditableException extends DomainException
{
    public function __construct(
        public readonly JournalEntryEditState $state,
        public readonly ?string $targetDate = null,
    ) {
        parent::__construct($targetDate === null
            ? __('app.journal_entry_not_editable', ['reason' => $state->label()])
            : __('app.journal_entry_target_date_locked', ['date' => $targetDate, 'reason' => $state->label()]));
    }

    public function errorCode(): string
    {
        return 'entry_not_editable';
    }
}
