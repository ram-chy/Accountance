<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\BankReconciliation;
use App\Models\User;

class BankReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::BankReconciliationView->value);
    }

    public function view(User $user, BankReconciliation $reconciliation): bool
    {
        return $user->can(PermissionName::BankReconciliationView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::BankReconciliationCreate->value);
    }

    public function update(User $user, BankReconciliation $reconciliation): bool
    {
        return $user->can(PermissionName::BankReconciliationUpdate->value);
    }

    public function delete(User $user, BankReconciliation $reconciliation): bool
    {
        return $user->can(PermissionName::BankReconciliationUpdate->value);
    }

    public function complete(User $user, BankReconciliation $reconciliation): bool
    {
        return $user->can(PermissionName::BankReconciliationComplete->value);
    }

    public function reopen(User $user, BankReconciliation $reconciliation): bool
    {
        return $user->can(PermissionName::BankReconciliationReopen->value);
    }
}
