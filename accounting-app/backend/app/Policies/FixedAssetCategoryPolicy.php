<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\FixedAssetCategory;
use App\Models\User;

/**
 * Fixed asset category authorization.
 *
 * A category is master data: the template a new asset copies its five accounts and
 * useful life from. Editing one therefore changes what the NEXT asset will do and
 * changes nothing about the assets already written down, which is why the same
 * `update` grant covers it whether the change is cosmetic or re-points an account.
 *
 * There is no separate `deactivate` or `activate` ability. The two are the same
 * lifecycle flag changed in opposite directions, and a role that may edit a category
 * at all may retire it; splitting them would create a grant that exists for one
 * endpoint and is remembered only by the person who wrote it. `delete` is separate
 * because it is irreversible and the service only permits it for a category with no
 * assets.
 *
 * No method re-checks company membership. The accounting route binding resolves a
 * FixedAssetCategory scoped to the active company, so a controller is handed an
 * object already known to belong to it and a foreign id has 404ed before this class
 * runs.
 */
class FixedAssetCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FixedAssetsView->value);
    }

    public function view(User $user, FixedAssetCategory $category): bool
    {
        return $user->can(PermissionName::FixedAssetsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::FixedAssetsCreate->value);
    }

    public function update(User $user, FixedAssetCategory $category): bool
    {
        return $user->can(PermissionName::FixedAssetsUpdate->value);
    }

    public function delete(User $user, FixedAssetCategory $category): bool
    {
        return $user->can(PermissionName::FixedAssetsDelete->value);
    }
}
