<?php

namespace App\Domains\Projects\Policies;

use App\Domains\Organizations\Enums\Permission;
use App\Domains\Projects\Models\AllocationReview;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization for AllocationReview records (spec 009): visible with projects.view,
 * changed only with projects.allocate.
 */
class AllocationReviewPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsView);
    }

    public function view(User $user, AllocationReview $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::ProjectsAllocate);
    }

    public function update(User $user, AllocationReview $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsAllocate);
    }

    public function delete(User $user, AllocationReview $record): bool
    {
        return $this->belongsToOrganization($user, $record)
            && $user->hasPermissionTo(Permission::ProjectsAllocate);
    }
}
