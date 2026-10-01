<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;

/**
 * Customer authorization.
 *
 * Company membership is not checked here. It is checked by the company-scoped
 * route binding in AppServiceProvider, which means a customer belonging to
 * another company never reaches a policy method at all - the route 404s first.
 * Re-checking membership in the policy would be redundant for every path that
 * goes through HTTP, and would still be bypassable by a direct service call,
 * which is why the services scope their own queries instead.
 *
 * As in JournalPolicy, no method inspects is_active. Whether a customer may be
 * invoiced is a business rule enforced by CustomerService, and it holds for
 * callers that never touch HTTP.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CustomersView->value);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can(PermissionName::CustomersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::CustomersCreate->value);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(PermissionName::CustomersUpdate->value);
    }

    /**
     * Deactivation is its own permission because it is how a customer is removed
     * from operational use without touching history - the closest thing this
     * master has to deletion, and the action that needs the most thought.
     */
    public function deactivate(User $user, Customer $customer): bool
    {
        return $user->can(PermissionName::CustomersDeactivate->value);
    }

    public function activate(User $user, Customer $customer): bool
    {
        return $user->can(PermissionName::CustomersDeactivate->value);
    }
}
