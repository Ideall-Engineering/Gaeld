<?php

namespace App\Domains\Projects\Policies;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Projects\Models\Acquisition;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization for Acquisition records (spec 009): visible with projects.view,
 * changed only with projects.manage.
 */
class AcquisitionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsView);
    }

    public function view(User $user, Acquisition $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function update(User $user, Acquisition $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function delete(User $user, Acquisition $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }
}
