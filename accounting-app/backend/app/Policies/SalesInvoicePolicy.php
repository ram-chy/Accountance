<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\SalesInvoice;
use App\Models\User;

/**
 * Sales invoice authorization.
 *
 * Post and delete are separate permissions from update on purpose.
 *
 *  - update edits a draft, which has no accounting effect and is trivially
 *    discardable.
 *  - post makes the document part of the permanent record.
 *  - delete removes a draft. It is not a general "delete invoice" grant: the
 *    service refuses a posted invoice outright, so this permission can only ever
 *    discard work that never became a fact. It is nonetheless its own permission
 *    rather than a rider on update, because a role that can edit a draft has no
 *    business also being able to destroy it.
 *
 * No method checks the invoice's status. That is a business rule, enforced in
 * SalesInvoiceService, so it holds for callers that bypass HTTP.
 */
class SalesInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::SalesInvoicesView->value);
    }

    public function view(User $user, SalesInvoice $invoice): bool
    {
        return $user->can(PermissionName::SalesInvoicesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::SalesInvoicesCreate->value);
    }

    public function update(User $user, SalesInvoice $invoice): bool
    {
        return $user->can(PermissionName::SalesInvoicesUpdate->value);
    }

    public function post(User $user, SalesInvoice $invoice): bool
    {
        return $user->can(PermissionName::SalesInvoicesPost->value);
    }

    public function delete(User $user, SalesInvoice $invoice): bool
    {
        return $user->can(PermissionName::SalesInvoicesDelete->value);
    }
}
