<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\User;

/**
 * Company authorization.
 *
 * Two independent checks must both pass, in this order:
 *
 *   1. MEMBERSHIP  - is this user part of the company being addressed?
 *   2. PERMISSION  - may this role perform this action inside a company?
 *
 * Checking only the permission would let any user holding `companies.view`
 * read every company simply by changing the id in the URL, so membership is
 * verified first and short-circuits. Both checks live in this single policy so
 * a future module cannot accidentally implement only one of them.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        // Listing is scoped to the user's own companies by the query, so only
        // the capability check applies here.
        return $user->can(PermissionName::CompaniesView->value);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->isMember($user, $company)
            && $user->can(PermissionName::CompaniesView->value);
    }

    public function create(User $user): bool
    {
        // Creating a company is not scoped to an existing company.
        return $user->can(PermissionName::CompaniesCreate->value);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->isMember($user, $company)
            && $user->can(PermissionName::CompaniesUpdate->value);
    }

    /**
     * Deactivate a company.
     *
     * Hard delete is intentionally not permitted: journals, ledger entries and
     * documents will reference this row, so removal is modelled as
     * `is_active = false`.
     */
    public function delete(User $user, Company $company): bool
    {
        return $this->isMember($user, $company)
            && $user->can(PermissionName::CompaniesDelete->value);
    }

    public function viewSettings(User $user, Company $company): bool
    {
        return $this->isMember($user, $company)
            && $user->can(PermissionName::CompanySettingsView->value);
    }

    public function updateSettings(User $user, Company $company): bool
    {
        return $this->isMember($user, $company)
            && $user->can(PermissionName::CompanySettingsUpdate->value);
    }

    /**
     * Read the company's currency/FX accounting controls.
     *
     * Company-scoped because the findings describe this company's ledger and
     * configuration, so membership is required in addition to the capability. It is
     * deliberately not folded into `accounting.reports.view`: the controls expose
     * configuration gaps (a missing base currency, unconfigured FX accounts) that a
     * report does not, so they are granted separately.
     */
    public function viewControls(User $user, Company $company): bool
    {
        return $this->isMember($user, $company)
            && $user->can(PermissionName::ControlsView->value);
    }

    /**
     * Switch the caller's active company.
     *
     * Any member of an active company may select it, regardless of role: this
     * only chooses which company the user is working in.
     */
    public function switch(User $user, Company $company): bool
    {
        return $this->isMember($user, $company) && $company->is_active;
    }

    private function isMember(User $user, Company $company): bool
    {
        return $company->hasMember($user);
    }
}
