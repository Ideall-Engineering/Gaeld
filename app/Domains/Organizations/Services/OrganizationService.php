<?php

namespace App\Domains\Organizations\Services;

use App\Domains\Expenses\Models\ExpenseCategory;
use App\Domains\Organizations\DTOs\CreateOrganizationData;
use App\Domains\Organizations\DTOs\UpdateCommunicationsData;
use App\Domains\Organizations\DTOs\UpdateInvoiceSettingsData;
use App\Domains\Organizations\DTOs\UpdateOrganizationData;
use App\Domains\Organizations\Enums\Role;
use App\Domains\Organizations\Events\MemberRemoved;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRUD operations for organizations and organization membership management.
 */
class OrganizationService
{
    // ──────────────────────────────────────────────────────────────
    //  CRUD
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a new organization and attach the owner.
     */
    public function create(User $owner, CreateOrganizationData $data): Organization
    {
        return DB::transaction(function () use ($owner, $data) {
            $org = Organization::create($data->toArray());

            foreach (ExpenseCategory::DEFAULT_CATEGORIES as $sortOrder => $name) {
                ExpenseCategory::create([
                    'organization_id' => $org->id,
                    'name' => $name,
                    'is_default' => true,
                    'sort_order' => $sortOrder,
                ]);
            }

            $org->users()->attach($owner->id, ['role' => 'owner']);

            $this->assignSpatieRole($owner, $org, Role::Owner);

            return $org;
        });
    }

    public function update(Organization $organization, UpdateOrganizationData $data): Organization
    {
        $organization->update($data->toArray());

        return $organization;
    }

    public function delete(Organization $organization): void
    {
        $organization->delete();
    }

    public function updateInvoiceSettings(Organization $organization, UpdateInvoiceSettingsData $data): Organization
    {
        $organization->update($data->toArray());

        return $organization;
    }

    public function updateCommunications(Organization $organization, UpdateCommunicationsData $data): Organization
    {
        $organization->update($data->toArray());

        return $organization;
    }

    // ──────────────────────────────────────────────────────────────
    //  Membership
    // ──────────────────────────────────────────────────────────────

    /**
     * Add a member to an organization.
     */
    public function addMember(Organization $organization, User $user, string $role = 'member'): void
    {
        $organization->users()->syncWithoutDetaching([
            $user->id => ['role' => $role],
        ]);

        $spatieRole = Role::tryFrom($role) ?? Role::Member;
        $this->assignSpatieRole($user, $organization, $spatieRole);
        $this->syncEmployeeLink($organization, $user, $spatieRole);
    }

    /**
     * Remove a member from an organization.
     */
    public function removeMember(Organization $organization, User $user): void
    {
        $organization->users()->detach($user->id);

        app()[PermissionRegistrar::class]->setPermissionsTeamId($organization->id);
        $user->roles()->detach();

        MemberRemoved::dispatch($organization, $user);
    }

    /**
     * Change a member's role within an organization.
     */
    public function changeMemberRole(Organization $organization, User $user, Role $role, ?Employee $employee = null): void
    {
        // Prevent removing the last owner
        if ($this->isLastOwner($organization, $user)) {
            throw ValidationException::withMessages([
                'role' => [__('app.cannot_change_last_owner')],
            ]);
        }

        $organization->users()->updateExistingPivot($user->id, ['role' => $role->value]);
        $this->assignSpatieRole($user, $organization, $role);
        $this->syncEmployeeLink($organization, $user, $role, $employee);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Check if removing/changing this user would leave the org without an owner.
     */
    public function isLastOwner(Organization $organization, User $user): bool
    {
        $ownerCount = $organization->users()
            ->wherePivot('role', 'owner')
            ->count();

        $isOwner = $organization->users()
            ->wherePivot('role', 'owner')
            ->where('users.id', $user->id)
            ->exists();

        return $isOwner && $ownerCount === 1;
    }

    private function assignSpatieRole(User $user, Organization $organization, Role $role): void
    {
        app()[PermissionRegistrar::class]->setPermissionsTeamId($organization->id);
        $user->syncRoles([$role->value]);
    }

    private function syncEmployeeLink(Organization $organization, User $user, Role $role, ?Employee $selectedEmployee = null): void
    {
        if ($role !== Role::Employee) {
            Employee::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->update(['user_id' => null]);

            return;
        }

        // An admin who names a record on the members screen gets told when it
        // is not theirs to hand out — 'employee_id' is a field they can see.
        if ($selectedEmployee !== null) {
            if ($selectedEmployee->organization_id !== $organization->id
                || ($selectedEmployee->user_id !== null && $selectedEmployee->user_id !== $user->id)) {
                throw ValidationException::withMessages([
                    'employee_id' => [__('app.employee_already_linked')],
                ]);
            }

            $selectedEmployee->update(['user_id' => $user->id]);

            return;
        }

        // Nobody named one, so the address decides. Accepting an invitation
        // comes through here, and the person doing it cannot create a payroll
        // record or merge two of them — so a missing or ambiguous match costs
        // them the link, never the membership. InvitationService::invite()
        // already insists on exactly one unlinked record at invite time; this
        // is the window after that, in which the record can be deleted,
        // re-addressed, linked elsewhere, or gain a twin.
        $candidates = Employee::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
            ->where(function ($query) use ($user): void {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            Log::warning('Employee role left without an employee record', [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'candidates' => $candidates->count(),
            ]);

            return;
        }

        $candidates->first()->update(['user_id' => $user->id]);
    }
}
