<?php

namespace App\Domains\Banking\Policies;

use App\Domains\Banking\Models\BankRule;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization for editable posting rules.
 *
 * Reading a rule needs no more than banking access, but writing one is an
 * accounting decision — a rule dictates which account and which VAT treatment a
 * payment lands under — so creating and editing require accounting rights, not
 * merely permission to look at a bank statement.
 */
class BankRulePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::BankingView);
    }

    public function view(User $user, BankRule $rule): bool
    {
        return $this->belongsToOrganization($user, $rule)
            && $user->hasPermissionTo(Permission::BankingView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::AccountingCreate);
    }

    public function update(User $user, BankRule $rule): bool
    {
        return $this->belongsToOrganization($user, $rule)
            && $user->hasPermissionTo(Permission::AccountingEdit);
    }

    public function delete(User $user, BankRule $rule): bool
    {
        return $this->belongsToOrganization($user, $rule)
            && $user->hasPermissionTo(Permission::AccountingDelete);
    }

    /**
     * Deciding on a proposal is a reconciliation act, not a rule edit.
     */
    public function decide(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::BankingReconcile);
    }
}
