<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Account;
use App\Models\User;

/**
 * Chart of Accounts authorization.
 *
 * Membership is not re-checked here. It was already established by the
 * company.context middleware before the request reached here, and the
 * controllers additionally scope every lookup to the active company - a request
 * naming another company's account gets a 404 from that scope, not a 403 from
 * this policy. Repeating the membership query here would add a second lookup to
 * answer a question the routing layer has already answered.
 */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        // The listing query is scoped to the active company, so only the
        // capability check applies.
        return $user->can(PermissionName::AccountsView->value);
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can(PermissionName::AccountsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::AccountsCreate->value);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can(PermissionName::AccountsUpdate->value);
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->can(PermissionName::AccountsDelete->value);
    }

    public function activate(User $user, Account $account): bool
    {
        return $user->can(PermissionName::AccountsActivate->value);
    }

    public function deactivate(User $user, Account $account): bool
    {
        return $user->can(PermissionName::AccountsDeactivate->value);
    }
}
