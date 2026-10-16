<?php

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Revisions are part of the books: anyone who may read the journal may read
 * them, and nobody may create, change or delete one by hand. They are only
 * written by the amend and delete actions.
 */
class JournalEntryRevisionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function view(User $user, JournalEntryRevision $revision): bool
    {
        return $this->belongsToOrganization($user, $revision)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, JournalEntryRevision $revision): bool
    {
        return false;
    }

    public function delete(User $user, JournalEntryRevision $revision): bool
    {
        return false;
    }
}
