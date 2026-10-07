<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\CompanyFxSetting;
use App\Models\User;

/**
 * The company's realised FX gain/loss account pair.
 *
 * Membership is established by the company.context middleware before the request
 * reaches this policy, and the record is always resolved for the active company
 * rather than from a route id, so a setting for another company cannot be
 * addressed at all.
 *
 * Both abilities ride on `accounting.fx.update` rather than on the company-settings
 * permissions. This is configuration that only matters to an FX-transacting
 * company, and the role that posts foreign settlements is the role that must be
 * able to name the accounts - which is the Accountant, who is not granted
 * `companies.settings.*`. Reading it is gated by the same grant because a caller
 * that may change which account FX posts to must be able to see which account is
 * currently configured; there is no separate read-only FX audience.
 */
class CompanyFxSettingPolicy
{
    public function view(User $user, CompanyFxSetting $setting): bool
    {
        return $user->can(PermissionName::FxUpdate->value);
    }

    public function update(User $user, CompanyFxSetting $setting): bool
    {
        return $user->can(PermissionName::FxUpdate->value);
    }
}
