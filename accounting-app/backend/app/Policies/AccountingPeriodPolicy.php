<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\AccountingPeriod;
use App\Models\User;

/**
 * Accounting period authorization.
 *
 * There is deliberately no `reopen` method. Phase 4 has no reopen endpoint, so
 * there is nothing to authorise; adding a policy for an action that cannot be
 * invoked would suggest the capability exists.
 */
class AccountingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::PeriodsView->value);
    }

    public function view(User $user, AccountingPeriod $period): bool
    {
        return $user->can(PermissionName::PeriodsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::PeriodsCreate->value);
    }

    public function update(User $user, AccountingPeriod $period): bool
    {
        return $user->can(PermissionName::PeriodsUpdate->value);
    }

    /**
     * Closing a period is one-way and removes the ability to post into it, so it
     * is its own permission rather than part of periods.update.
     */
    public function close(User $user, AccountingPeriod $period): bool
    {
        return $user->can(PermissionName::PeriodsClose->value);
    }
}
