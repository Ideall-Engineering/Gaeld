<?php

namespace App\Domains\Projects\Policies;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Projects\Models\SteeringItem;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization for SteeringItem records (spec 009): visible with projects.view,
 * visible and changed only with projects.manage; the steering page itself is for owners only.
 */
class SteeringItemPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function view(User $user, SteeringItem $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function update(User $user, SteeringItem $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function delete(User $user, SteeringItem $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }
}
