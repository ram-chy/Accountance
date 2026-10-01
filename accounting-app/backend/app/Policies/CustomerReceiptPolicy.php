<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\CustomerReceipt;
use App\Models\User;

/**
 * Customer receipt authorization.
 *
 * `update` exists because a draft receipt is editable - a draft with a wrong
 * amount or a mis-keyed allocation would otherwise be unfixable, and delete +
 * recreate is not an option because document numbers are never reused. The grant
 * only authorises the draft edit; CustomerReceiptService still refuses the edit
 * once posted, so this permission cannot be used to mutate a posted receipt.
 */
class CustomerReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CustomerReceiptsView->value);
    }

    public function view(User $user, CustomerReceipt $receipt): bool
    {
        return $user->can(PermissionName::CustomerReceiptsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::CustomerReceiptsCreate->value);
    }

    public function update(User $user, CustomerReceipt $receipt): bool
    {
        return $user->can(PermissionName::CustomerReceiptsUpdate->value);
    }

    public function post(User $user, CustomerReceipt $receipt): bool
    {
        return $user->can(PermissionName::CustomerReceiptsPost->value);
    }

    public function delete(User $user, CustomerReceipt $receipt): bool
    {
        return $user->can(PermissionName::CustomerReceiptsDelete->value);
    }
}
