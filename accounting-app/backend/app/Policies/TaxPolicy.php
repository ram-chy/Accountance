<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Tax;
use App\Models\TaxAccountMapping;
use App\Models\TaxRate;
use App\Models\User;

/**
 * Tax configuration authorization.
 *
 * Membership is not re-checked here, for the reason documented in AccountPolicy:
 * the company.context middleware established it before the request arrived, and
 * the controllers scope every lookup to the active company so a request naming
 * another company's tax is a 404 before it reaches a policy method.
 *
 * Three model classes are covered because three different resources are exposed,
 * and a single policy on Tax alone would leave rates and mappings either
 * unauthorized or authorized by hand in the controller. `calculate` and
 * `report.view` are abilities rather than resource actions, so they are declared
 * here as gates over Tax - the model the capability acts on - rather than
 * invented as methods that look like they operate on a tax record.
 */
class TaxPolicy
{
    public function viewAny(User $user): bool
    {
        // The listing query is scoped to the active company, so only the
        // capability check applies.
        return $user->can(PermissionName::TaxView->value);
    }

    public function view(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::TaxCreate->value);
    }

    public function update(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxUpdate->value);
    }

    public function delete(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxDelete->value);
    }

    /**
     * Activation and deactivation are updates, not deletions, and are gated as such.
     *
     * The brief asks for deactivation "when historical references exist" - it is the
     * safe alternative to destroying a tax, not a privileged act of its own. Gating
     * it on `delete` would mean a role that may deactivate a tax to stop charging it
     * could not reactivate it when the mistake was corrected, and a role that may
     * delete would gain no additional reach from it. So both directions ride on
     * `accounting.tax.update`, which is the capability that already means "change
     * how this tax behaves".
     */
    public function activate(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxUpdate->value);
    }

    public function deactivate(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxUpdate->value);
    }

    public function calculate(User $user): bool
    {
        return $user->can(PermissionName::TaxCalculate->value);
    }

    public function report(User $user): bool
    {
        return $user->can(PermissionName::TaxReportView->value);
    }

    /**
     * Rates and mappings inherit the configuration capability of the tax they
     * belong to.
     *
     * Deliberately not separate permissions. A rate has no independent meaning -
     * `10%` on its own says nothing - so granting access to rates separately from
     * the tax would create a way to read a company's effective tax rate while
     * being unable to see which tax it applies to, which is the disclosure
     * PermissionName documents as the reason Staff does not get `calculate`.
     */
    public function viewAnyRate(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxView->value);
    }

    public function viewRate(User $user, TaxRate $rate): bool
    {
        return $user->can(PermissionName::TaxView->value);
    }

    public function createRate(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxCreate->value);
    }

    public function updateRate(User $user, TaxRate $rate): bool
    {
        return $user->can(PermissionName::TaxUpdate->value);
    }

    public function deleteRate(User $user, TaxRate $rate): bool
    {
        return $user->can(PermissionName::TaxDelete->value);
    }

    /**
     * As with the tax itself, a rate is deactivated and reactivated as an update.
     */
    public function activateRate(User $user, TaxRate $rate): bool
    {
        return $user->can(PermissionName::TaxUpdate->value);
    }

    public function deactivateRate(User $user, TaxRate $rate): bool
    {
        return $user->can(PermissionName::TaxUpdate->value);
    }

    public function viewMapping(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxView->value);
    }

    public function updateMapping(User $user, Tax $tax): bool
    {
        return $user->can(PermissionName::TaxUpdate->value);
    }

    /**
     * A mapping is readable through its tax only; there is no route that addresses
     * one directly. Declared so the ability exists if one is added.
     */
    public function viewAnyMapping(User $user, TaxAccountMapping $mapping): bool
    {
        return $user->can(PermissionName::TaxView->value);
    }
}
