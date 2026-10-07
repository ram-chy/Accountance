<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Currency;
use App\Models\User;

/**
 * Currency master authorization.
 *
 * There is no membership check here, and that is the difference between this
 * policy and every accounting policy. A currency is GLOBAL reference data: it
 * belongs to no company, so there is no company to be a member of and no tenant
 * boundary to enforce. The only question is whether the role holds the
 * capability.
 *
 * That makes the capability the whole guard, which is why the write permissions
 * are withheld from the Accountant role and held only by Admin (through its
 * wildcard). Creating a currency changes the unit every tenant may bill in.
 *
 * There is deliberately no `delete` ability. A currency that a posted document
 * or an exchange rate referenced must stay readable forever, so the lifecycle
 * ends at deactivation; a policy method for deletion would advertise an act the
 * system does not perform.
 */
class CurrencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CurrencyView->value);
    }

    public function view(User $user, Currency $currency): bool
    {
        return $user->can(PermissionName::CurrencyView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::CurrencyCreate->value);
    }

    public function update(User $user, Currency $currency): bool
    {
        return $user->can(PermissionName::CurrencyUpdate->value);
    }

    /**
     * Activation and deactivation are the reversible alternative to deletion.
     *
     * They ride on their own grants rather than on `update` because deactivating a
     * currency removes it from every tenant's pickers at once - a global act, unlike
     * renaming one.
     */
    public function activate(User $user, Currency $currency): bool
    {
        return $user->can(PermissionName::CurrencyActivate->value);
    }

    public function deactivate(User $user, Currency $currency): bool
    {
        return $user->can(PermissionName::CurrencyDeactivate->value);
    }
}
