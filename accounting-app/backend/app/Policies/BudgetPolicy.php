<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Budget;
use App\Models\User;

/**
 * Budget authorization.
 *
 * Five capabilities, and the boundaries between them are the control the phase
 * adds:
 *
 *  - `view`    reads a budget, its lines and the variance report.
 *  - `create`  raises a new draft, and raises a revision of an approved one.
 *  - `update`  edits a draft and maintains its lines.
 *  - `delete`  discards a draft.
 *  - `approve` finalizes a draft, after which it is immutable.
 *
 * `revise` maps to `create` rather than to its own grant. A revision does not
 * modify the approved budget - it makes a NEW draft version - so it is a create,
 * and the role that prepares plans is the role that supersedes them. Approving,
 * by contrast, is deliberately separate: a role may be trusted to prepare a plan
 * without being trusted to commit it.
 *
 * Line endpoints authorize against the parent budget, because a line has no
 * independent existence and the ability being exercised is "edit this draft".
 * They are reached only through a route whose `{budget}` binding already scoped
 * the budget to the active company.
 *
 * No method re-checks company membership. The accounting route binding resolves
 * a Budget scoped to the active company, so a foreign id has 404ed before this
 * class runs, exactly as it does for every other accounting policy.
 */
class BudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::BudgetsView->value);
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->can(PermissionName::BudgetsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::BudgetsCreate->value);
    }

    public function update(User $user, Budget $budget): bool
    {
        return $user->can(PermissionName::BudgetsUpdate->value);
    }

    public function delete(User $user, Budget $budget): bool
    {
        return $user->can(PermissionName::BudgetsDelete->value);
    }

    public function approve(User $user, Budget $budget): bool
    {
        return $user->can(PermissionName::BudgetsApprove->value);
    }

    /**
     * Revisions are creations of a new version, so they ride the create grant.
     */
    public function revise(User $user, Budget $budget): bool
    {
        return $user->can(PermissionName::BudgetsCreate->value);
    }
}
