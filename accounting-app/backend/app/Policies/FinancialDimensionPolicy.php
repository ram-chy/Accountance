<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\FinancialDimension;
use App\Models\User;

/**
 * Financial dimension authorization.
 *
 * One policy covers a dimension AND its values, for the same reason BudgetPolicy
 * covers a budget's lines: a value has no independent existence, every value
 * endpoint is addressed through its dimension, and a second policy deciding the
 * same four questions would drift from the first. The controller authorizes
 * against the parent dimension for a nested value route.
 *
 * Activation and deactivation ride `update` rather than being their own grants.
 * Flipping is_active is the reversible form of the change `update` already allows,
 * and a separate capability would mean a role that may rename a cost centre could
 * not retire one - one more grant to remember for no extra control. This follows
 * the FixedAssetCategory and Account precedent.
 *
 * Membership is not re-checked here: the company.context middleware established it
 * before the request arrived, and the scoped route binding already refuses another
 * company's dimension with a 404. Repeating the query would answer a question the
 * routing layer has already answered.
 */
class FinancialDimensionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::DimensionsView->value);
    }

    public function view(User $user, FinancialDimension $dimension): bool
    {
        return $user->can(PermissionName::DimensionsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::DimensionsCreate->value);
    }

    public function update(User $user, FinancialDimension $dimension): bool
    {
        return $user->can(PermissionName::DimensionsUpdate->value);
    }

    public function delete(User $user, FinancialDimension $dimension): bool
    {
        return $user->can(PermissionName::DimensionsDelete->value);
    }
}
