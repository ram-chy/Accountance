<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\CreditDebitNote;
use App\Models\User;

/**
 * Credit/debit note authorization.
 *
 * Five abilities over one model, and the split is the same one SalesInvoicePolicy
 * makes because a note has the same three states of consequence as the document it
 * adjusts:
 *
 *  - view      reads the note and what is left adjustable on its source.
 *  - create    drafts a new adjustment. No accounting effect.
 *  - update    edits a draft. The service refuses a posted note under a row lock.
 *  - post      makes the adjustment part of the permanent record.
 *  - delete    discards a draft. Not a grant to remove a posted adjustment - the
 *              service refuses those outright, and there is no cancellation
 *              mechanism because the application has none. Recording a further
 *              note against the original document is the only correction, exactly
 *              as it is for an invoice.
 *
 * Note that `update` and `delete` are separate rather than delete being a rider on
 * update: a role that can edit a draft has no business being able to destroy one,
 * and the permission can be granted deliberately later without a code change.
 *
 * No method reads the note's status. Whether a note may still be changed is a
 * business rule and it lives in CreditDebitNoteService, so it holds for any caller
 * that reaches the service without going through HTTP - which is the only way the
 * rule is worth anything.
 */
class CreditDebitNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CreditDebitNotesView->value);
    }

    public function view(User $user, CreditDebitNote $note): bool
    {
        return $user->can(PermissionName::CreditDebitNotesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::CreditDebitNotesCreate->value);
    }

    public function update(User $user, CreditDebitNote $note): bool
    {
        return $user->can(PermissionName::CreditDebitNotesUpdate->value);
    }

    public function post(User $user, CreditDebitNote $note): bool
    {
        return $user->can(PermissionName::CreditDebitNotesPost->value);
    }

    public function delete(User $user, CreditDebitNote $note): bool
    {
        return $user->can(PermissionName::CreditDebitNotesDelete->value);
    }
}
