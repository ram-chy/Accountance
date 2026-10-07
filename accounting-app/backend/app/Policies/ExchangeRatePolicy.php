<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\ExchangeRate;
use App\Models\User;

/**
 * Exchange-rate authorization.
 *
 * Membership is not re-checked here, for the reason documented in TaxPolicy and
 * AccountPolicy: the company.context middleware established it before the request
 * arrived, and every rate lookup is scoped to the active company, so a rate id from
 * another company is a 404 before a policy method runs.
 *
 * Activation and deactivation ride on `accounting.exchange_rate.update` rather than
 * on their own grants. Withdrawing a rate stops it pricing new documents; it does
 * not remove it from the ones it already priced, so it is a change to how the rate
 * behaves rather than a privileged act, and a role that may deactivate a mistaken
 * rate must be able to reactivate it when the mistake is corrected.
 *
 * There is no `delete` ability. A rate a posted document used must stay readable
 * forever; the Phase 14 brief forbids casual modification of a rate already used to
 * price a document, and the service enforces that independently of this policy.
 */
class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::ExchangeRateView->value);
    }

    public function view(User $user, ExchangeRate $rate): bool
    {
        return $user->can(PermissionName::ExchangeRateView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::ExchangeRateCreate->value);
    }

    public function update(User $user, ExchangeRate $rate): bool
    {
        return $user->can(PermissionName::ExchangeRateUpdate->value);
    }

    public function activate(User $user, ExchangeRate $rate): bool
    {
        return $user->can(PermissionName::ExchangeRateUpdate->value);
    }

    public function deactivate(User $user, ExchangeRate $rate): bool
    {
        return $user->can(PermissionName::ExchangeRateUpdate->value);
    }
}
