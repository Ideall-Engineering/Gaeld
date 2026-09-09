<?php

namespace App\Domains\Automation\Policies;

use App\Domains\Automation\Models\AutomationRun;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Who may see, configure and trigger automations.
 *
 * Switching one on is an organizational decision, not an accounting one — it
 * decides what the system is allowed to do unattended — so it takes
 * organization.edit. Triggering a single run additionally requires whatever
 * permission the automation's own work would need, checked by the controller
 * against the handler.
 */
class AutomationPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function view(User $user, AutomationRun $run): bool
    {
        return $this->belongsToOrganization($user, $run)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function configure(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::OrganizationEdit);
    }
}
