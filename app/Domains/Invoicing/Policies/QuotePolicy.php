<?php

namespace App\Domains\Invoicing\Policies;

use App\Domains\Invoicing\Models\Quote;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Support\Policies\BasePolicy;

/**
 * Quotes follow the invoicing permissions: who may write invoices may write
 * quotes. Whether the quote's state allows a step is checked again in the
 * actions, since organization tokens skip the policy.
 */
class QuotePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::InvoicingView);
    }

    public function view(User $user, Quote $quote): bool
    {
        return $this->belongsToOrganization($user, $quote)
            && $user->hasPermissionTo(Permission::InvoicingView);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganization($user)
            && $user->hasPermissionTo(Permission::InvoicingCreate);
    }

    public function update(User $user, Quote $quote): bool
    {
        return $this->belongsToOrganization($user, $quote)
            && $user->hasPermissionTo(Permission::InvoicingEdit)
            && $quote->status->isEditable();
    }

    public function changeStatus(User $user, Quote $quote): bool
    {
        return $this->belongsToOrganization($user, $quote)
            && $user->hasPermissionTo(Permission::InvoicingEdit)
            && $quote->invoice_id === null;
    }

    public function convert(User $user, Quote $quote): bool
    {
        return $this->belongsToOrganization($user, $quote)
            && $user->hasPermissionTo(Permission::InvoicingCreate)
            && $quote->invoice_id === null;
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $this->belongsToOrganization($user, $quote)
            && $user->hasPermissionTo(Permission::InvoicingDelete)
            && $quote->status->isDeletable()
            && $quote->invoice_id === null;
    }
}
