<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Supplier;
use App\Models\User;

/**
 * Supplier authorization.
 *
 * The counterpart of CustomerPolicy: same structure, same reasoning, and
 * activation deliberately shares the deactivate permission. Reactivating a
 * supplier puts it back into operational use, so it is the same governance
 * decision as deactivating it - granting one without the other would leave a role
 * able to undo the other's work.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::SuppliersView->value);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can(PermissionName::SuppliersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::SuppliersCreate->value);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can(PermissionName::SuppliersUpdate->value);
    }

    public function deactivate(User $user, Supplier $supplier): bool
    {
        return $user->can(PermissionName::SuppliersDeactivate->value);
    }

    public function activate(User $user, Supplier $supplier): bool
    {
        return $user->can(PermissionName::SuppliersDeactivate->value);
    }
}
