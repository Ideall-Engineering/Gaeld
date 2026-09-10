<?php

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization policy for the JournalCorrection record itself.
 *
 * The web and API controllers authorize the actual correction operations
 * (prepare/post/cancel/update-replacement) against the *original*
 * JournalEntry via {@see JournalEntryPolicy::correct()}, since eligibility
 * (source, already-corrected/-reversed) is a property of the original entry,
 * not of the correction record. This policy exists so a resolvable
 * authorization surface exists for the model itself — e.g. for a future
 * direct `$user->can('view', $journalCorrection)` check — and stays
 * consistent with the correction being scoped to `AccountingEdit`.
 */
class JournalCorrectionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function view(User $user, JournalCorrection $correction): bool
    {
        return $this->belongsToOrganization($user, $correction)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function update(User $user, JournalCorrection $correction): bool
    {
        return $this->belongsToOrganization($user, $correction)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && $correction->isDraft();
    }

    public function delete(User $user, JournalCorrection $correction): bool
    {
        return $this->belongsToOrganization($user, $correction)
            && $user->hasPermissionTo(Permission::AccountingEdit)
            && $correction->isDraft();
    }
}
