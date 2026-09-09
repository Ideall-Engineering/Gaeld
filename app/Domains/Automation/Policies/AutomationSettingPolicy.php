<?php

namespace App\Domains\Automation\Policies;

use App\Domains\Automation\Models\AutomationSetting;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Who may change what the system is allowed to do unattended.
 *
 * Deliberately narrower than reading the automation screen: seeing that a
 * workflow exists is harmless, deciding it may act without supervision is an
 * organizational choice. For anything that writes, the controller additionally
 * requires the permission the workflow's own work would need, so switching on
 * ledger automation takes ledger rights and not merely settings rights.
 */
class AutomationSettingPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function view(User $user, AutomationSetting $setting): bool
    {
        return $this->belongsToOrganization($user, $setting)
            && $user->hasPermissionTo(Permission::AccountingView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::OrganizationEdit);
    }

    public function update(User $user, AutomationSetting $setting): bool
    {
        return $this->belongsToOrganization($user, $setting)
            && $user->hasPermissionTo(Permission::OrganizationEdit);
    }

    public function delete(User $user, AutomationSetting $setting): bool
    {
        return $this->update($user, $setting);
    }
}
