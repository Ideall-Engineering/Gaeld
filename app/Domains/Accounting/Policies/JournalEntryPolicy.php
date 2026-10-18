<?php

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
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

    /**
     * The receipt link is a pointer, not part of the booking, so it may be
     * set on posted entries as well.
     */
    public function linkDocument(User $user, JournalEntry $entry): bool
    {
        return $entry->archived_at === null
            && $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingEdit);
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

    /**
     * Whether a posted entry may be changed in place.
     *
     * The period, ownership and organization mode are decided by
     * {@see JournalEntryEditStateService}; this only adds who may do it.
     */
    public function amend(User $user, JournalEntry $entry): bool
    {
        return $this->attemptAmend($user, $entry)
            && $entry->is_posted
            && app(JournalEntryEditStateService::class)->stateFor($entry)->isAmendable();
    }

    /** Whether the texts of this posted entry may be changed now. */
    public function amendText(User $user, JournalEntry $entry): bool
    {
        return $this->attemptAmend($user, $entry)
            && app(JournalEntryEditStateService::class)->textStateFor($entry)->isAmendable();
    }

    /**
     * Whether the user may try to amend or delete this posted entry at all.
     *
     * The API authorizes with this and leaves the entry's edit state to the
     * action, which answers a locked entry with a stable error code and the
     * reason instead of a bare 403.
     */
    public function attemptAmend(User $user, JournalEntry $entry): bool
    {
        return $this->belongsToOrganization($user, $entry)
            && $user->hasPermissionTo(Permission::AccountingAmendPosted);
    }

    /** Whether a posted entry may be deleted; same rules as {@see amend()}. */
    public function deletePosted(User $user, JournalEntry $entry): bool
    {
        return $this->amend($user, $entry);
    }
}
