<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\FixedAsset;
use App\Models\User;

/**
 * Fixed asset authorization.
 *
 * This is the one policy in the module with abilities beyond CRUD, because the
 * module has three irreversible operations that a user may need to be trusted with
 * separately:
 *
 *   capitalize  writes the asset's cost into the ledger and starts its life
 *   depreciate  writes a periodic charge into the ledger
 *   dispose     removes the cost from the ledger and books the result
 *
 * Each is its own permission and its own method. A role that may prepare a draft
 * asset - enter its cost, its serial number, its category - has not thereby been
 * given the right to post money, and the four permissions together let a company
 * separate the person who records an asset from the person who commits it. This
 * mirrors the draft/post split in CashBankTransactionPolicy rather than inventing a
 * second model of trust.
 *
 * `update` and `delete` can only ever touch a draft: FixedAssetService refuses both
 * under a row lock for anything capitalised, because a cost that is already in a
 * posted journal cannot be edited or deleted away. The grants therefore authorise
 * draft maintenance whatever the caller intends.
 *
 * No method re-checks company membership. The accounting route binding resolves a
 * FixedAsset scoped to the active company, so a controller is handed an object
 * already known to belong to it and a foreign id has 404ed before this class runs.
 */
class FixedAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FixedAssetsView->value);
    }

    public function view(User $user, FixedAsset $asset): bool
    {
        return $user->can(PermissionName::FixedAssetsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::FixedAssetsCreate->value);
    }

    public function update(User $user, FixedAsset $asset): bool
    {
        return $user->can(PermissionName::FixedAssetsUpdate->value);
    }

    public function delete(User $user, FixedAsset $asset): bool
    {
        return $user->can(PermissionName::FixedAssetsDelete->value);
    }

    public function capitalize(User $user, FixedAsset $asset): bool
    {
        return $user->can(PermissionName::FixedAssetsCapitalize->value);
    }

    public function depreciate(User $user, FixedAsset $asset): bool
    {
        return $user->can(PermissionName::FixedAssetsDepreciate->value);
    }

    public function dispose(User $user, FixedAsset $asset): bool
    {
        return $user->can(PermissionName::FixedAssetsDispose->value);
    }
}
