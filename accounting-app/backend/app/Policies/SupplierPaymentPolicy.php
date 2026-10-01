<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\SupplierPayment;
use App\Models\User;

/**
 * Supplier payment authorization.
 *
 * The counterpart of CustomerReceiptPolicy, including the `update` grant for
 * draft payments and the fact that the service, not this class, is what refuses
 * an edit after posting.
 */
class SupplierPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::SupplierPaymentsView->value);
    }

    public function view(User $user, SupplierPayment $payment): bool
    {
        return $user->can(PermissionName::SupplierPaymentsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::SupplierPaymentsCreate->value);
    }

    public function update(User $user, SupplierPayment $payment): bool
    {
        return $user->can(PermissionName::SupplierPaymentsUpdate->value);
    }

    public function post(User $user, SupplierPayment $payment): bool
    {
        return $user->can(PermissionName::SupplierPaymentsPost->value);
    }

    public function delete(User $user, SupplierPayment $payment): bool
    {
        return $user->can(PermissionName::SupplierPaymentsDelete->value);
    }
}
