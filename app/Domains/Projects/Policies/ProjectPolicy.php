<?php

namespace App\Domains\Projects\Policies;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Projects\Models\Project;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization for projects and for allocating journal lines to them
 * (spec 009).
 */
class ProjectPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsView);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->belongsToOrganization($user, $project)
            && $user->hasPermissionTo(Permission::ProjectsView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->belongsToOrganization($user, $project)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->belongsToOrganization($user, $project)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    /**
     * Change which projects and people journal lines belong to, whatever
     * state their entries are in.
     */
    public function steer(User $user): bool
    {
        // Owners only: the steering page shows what each owner draws and
        // sets how results are shared among them. organization.delete is
        // the one permission no role but the owner holds.
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsView)
            && $user->hasPermissionTo(Permission::OrganizationDelete);
    }

    /**
     * See the steering page, its export and its API (spec 012): everyone
     * who manages jobs, that is accountants and up. Changing keys, blocks
     * and plan items stays with steer.
     */
    public function viewSteering(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsView)
            && $user->hasPermissionTo(Permission::ProjectsManage);
    }

    public function allocate(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsAllocate);
    }
}
