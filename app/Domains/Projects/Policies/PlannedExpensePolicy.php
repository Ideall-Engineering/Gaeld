<?php

namespace App\Domains\Projects\Policies;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Projects\Models\PlannedExpense;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization for single expected expenses (spec 012): seen and changed by
 * everyone who manages jobs, like the steering page they appear on.
 */
class PlannedExpensePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsView)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function view(User $user, PlannedExpense $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsView)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsView)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function update(User $user, PlannedExpense $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsView)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function delete(User $user, PlannedExpense $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsView)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }
}
