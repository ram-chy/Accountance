<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\CashBankTransaction;
use App\Models\User;

/**
 * Cash/bank transaction authorization.
 *
 * `post` is a separate grant from `create` on purpose. Saving a draft moves no
 * money and is freely editable; posting writes to the permanent accounting
 * record. Splitting them is what lets a role prepare a movement without being
 * able to commit it, which is the distinction the phase brief asks for between
 * creating a transaction and posting it.
 *
 * `update` and `delete` follow the CustomerReceiptPolicy precedent: a draft with
 * a wrong amount or a mis-keyed account would otherwise be unfixable, since
 * delete-and-recreate burns a document number that is never reused. Neither
 * permission can touch a posted transaction - CashBankTransactionService refuses
 * the edit under a row lock and CashBankPostingService rejects a second post -
 * so these grants authorise draft operations only, whatever the caller intends.
 *
 * No method re-checks company membership. Route model binding resolves a
 * CashBankTransaction scoped to the active company, so the controller is handed
 * an object already known to belong to it; a transaction from another company
 * has 404ed before this class runs.
 */
class CashBankTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CashBankView->value);
    }

    public function view(User $user, CashBankTransaction $transaction): bool
    {
        return $user->can(PermissionName::CashBankView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::CashBankCreate->value);
    }

    public function update(User $user, CashBankTransaction $transaction): bool
    {
        return $user->can(PermissionName::CashBankUpdate->value);
    }

    public function post(User $user, CashBankTransaction $transaction): bool
    {
        return $user->can(PermissionName::CashBankPost->value);
    }

    public function delete(User $user, CashBankTransaction $transaction): bool
    {
        return $user->can(PermissionName::CashBankDelete->value);
    }
}
