<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Journal;
use App\Models\User;

/**
 * Journal authorization.
 *
 * Note there is no `update` method that checks the journal's status. Status is
 * enforced in JournalService, which refuses to touch a posted journal regardless
 * of who is asking. That is a stronger guarantee than a policy check: it holds
 * for callers that bypass the HTTP layer, and it cannot be forgotten by a future
 * controller that calls the service.
 */
class JournalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::JournalsView->value);
    }

    public function view(User $user, Journal $journal): bool
    {
        return $user->can(PermissionName::JournalsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::JournalsCreate->value);
    }

    public function update(User $user, Journal $journal): bool
    {
        return $user->can(PermissionName::JournalsUpdate->value);
    }

    public function delete(User $user, Journal $journal): bool
    {
        return $user->can(PermissionName::JournalsDelete->value);
    }

    /**
     * Permission to post.
     *
     * Deliberately distinct from journals.update: posting makes the entry part
     * of the permanent accounting record and cannot be undone through this API,
     * so it warrants its own grant rather than riding along with draft editing.
     */
    public function post(User $user, Journal $journal): bool
    {
        return $user->can(PermissionName::JournalsPost->value);
    }
}
