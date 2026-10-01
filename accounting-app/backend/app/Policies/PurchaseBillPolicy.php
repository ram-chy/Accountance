<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\PurchaseBill;
use App\Models\User;

/**
 * Purchase bill authorization.
 *
 * Structurally identical to SalesInvoicePolicy, including the reasoning behind
 * the separate post and delete grants.
 */
class PurchaseBillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::PurchasesBillsView->value);
    }

    public function view(User $user, PurchaseBill $bill): bool
    {
        return $user->can(PermissionName::PurchasesBillsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::PurchasesBillsCreate->value);
    }

    public function update(User $user, PurchaseBill $bill): bool
    {
        return $user->can(PermissionName::PurchasesBillsUpdate->value);
    }

    public function post(User $user, PurchaseBill $bill): bool
    {
        return $user->can(PermissionName::PurchasesBillsPost->value);
    }

    public function delete(User $user, PurchaseBill $bill): bool
    {
        return $user->can(PermissionName::PurchasesBillsDelete->value);
    }
}
