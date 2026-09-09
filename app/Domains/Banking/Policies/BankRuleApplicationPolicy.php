<?php

namespace App\Domains\Banking\Policies;

use App\Domains\Banking\Models\BankRuleApplication;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Authorization for individual rule proposals.
 *
 * Deciding is a reconciliation act — it settles how a bank movement will be
 * booked — so it takes the reconcile permission rather than rule-editing rights.
 * The organization check is belt and braces next to the global scope on the
 * model: it keeps the guarantee attached to the record rather than to whatever
 * query happened to load it.
 */
class BankRuleApplicationPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::BankingView);
    }

    public function view(User $user, BankRuleApplication $application): bool
    {
        return $this->belongsToOrganization($user, $application)
            && $user->hasPermissionTo(Permission::BankingView);
    }

    public function decide(User $user, BankRuleApplication $application): bool
    {
        return $this->belongsToOrganization($user, $application)
            && $user->hasPermissionTo(Permission::BankingReconcile);
    }
}
