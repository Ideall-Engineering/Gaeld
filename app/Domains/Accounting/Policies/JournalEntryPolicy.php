<?php

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization policy for journal entry operations.
 */
class JournalEntryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingCreate);
    }

    public function update(User $user, JournalEntry $entry): bool
    {
        if ($entry->archived_at !== null) {
            return false;
        }

        return $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && ! $entry->is_posted;
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        if ($entry->archived_at !== null) {
            return false;
        }

        return $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingDelete)
            && ! $entry->is_posted;
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && ! $entry->is_posted;
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && $entry->is_posted;
    }

    /**
     * Whether a guided correction may be started on this entry.
     *
     * Only checks authorization and the entry's basic postable state.
     * Eligibility (source, already corrected/reversed) is enforced by
     * {@see PrepareJournalCorrectionAction}
     * with specific, stable error codes — duplicating it here would only
     * let this check drift from the source of truth.
     */
    public function correct(User $user, JournalEntry $entry): bool
    {
        if ($entry->archived_at !== null) {
            return false;
        }

        return $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && $entry->is_posted;
    }
}
