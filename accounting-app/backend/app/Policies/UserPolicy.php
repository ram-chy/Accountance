<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * User management authorization.
 *
 * Every user-management permission is granted only through the Admin role.
 * A user can never grant themselves a permission, and no policy method here
 * consults input supplied by the caller about the caller's own privileges.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::UsersView->value);
    }

    public function view(User $user, User $target): bool
    {
        return $user->can(PermissionName::UsersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::UsersCreate->value);
    }

    public function update(User $user, User $target): bool
    {
        return $user->can(PermissionName::UsersUpdate->value);
    }

    /**
     * Deactivate a user.
     *
     * Hard delete is intentionally not permitted. Users will be referenced by
     * transactions, journals and audit records in later phases, so removal is
     * modelled as `is_active = false`.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->can(PermissionName::UsersDelete->value);
    }
}
