<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\AccountingPeriod;
use App\Models\FinancialYear;
use App\Models\User;

/**
 * Accounting-period and financial-year authorization.
 *
 * Both models are authorized here rather than through a FinancialYearPolicy for
 * two reasons: a financial year is the parent grouping of periods and has exactly
 * the same four capabilities, and the two are governed by the same permission set
 * (see PermissionName). Splitting them would mean two policies deciding the same
 * question, and a future edit that added an action to one but not the other.
 *
 * Company isolation is NOT re-implemented here. The route binding on `period` and
 * `financialYear` already resolves records within the active company, so a
 * controller holding one of these models is holding a record that belongs to the
 * current company. A foreign id 404s before any policy method runs.
 */
class AccountingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::PeriodsView->value);
    }

    public function view(User $user, AccountingPeriod $period): bool
    {
        return $user->can(PermissionName::PeriodsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::PeriodsCreate->value);
    }

    public function update(User $user, AccountingPeriod $period): bool
    {
        return $user->can(PermissionName::PeriodsUpdate->value);
    }

    /**
     * Closing a period is one-way in the sense that it removes the ability to post
     * into it, so it is its own permission rather than part of periods.update.
     */
    public function close(User $user, AccountingPeriod $period): bool
    {
        return $user->can(PermissionName::PeriodsClose->value);
    }

    /**
     * Reopening undoes an accounting-control decision, so it is separate from
     * close: a role that may close a period need not be able to reopen one.
     *
     * Phase 4 had no reopen method at all, because there was no reopen endpoint.
     * Phase 8 adds the endpoint and therefore adds the gate.
     */
    public function reopen(User $user, AccountingPeriod $period): bool
    {
        return $user->can(PermissionName::PeriodsReopen->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Financial year
    |--------------------------------------------------------------------------
    |
    | Same permissions as periods, deliberately. A year is viewed, maintained and
    | closed by the same roles, and reusing the set is what keeps a company's
    | fiscal calendar governable by one person without inventing a second
    | hierarchy of permissions to keep in step.
    */

    public function viewAnyYear(User $user): bool
    {
        return $user->can(PermissionName::PeriodsView->value);
    }

    public function viewYear(User $user, FinancialYear $year): bool
    {
        return $user->can(PermissionName::PeriodsView->value);
    }

    public function createYear(User $user): bool
    {
        return $user->can(PermissionName::PeriodsCreate->value);
    }

    public function updateYear(User $user, FinancialYear $year): bool
    {
        return $user->can(PermissionName::PeriodsUpdate->value);
    }

    /**
     * Generating periods is a create action against the year's children, so it
     * rides on the create permission rather than update: it writes rows, and a
     * role that may rename a period need not be able to add twelve of them.
     */
    public function generatePeriods(User $user, FinancialYear $year): bool
    {
        return $user->can(PermissionName::PeriodsCreate->value);
    }

    public function closeYear(User $user, FinancialYear $year): bool
    {
        return $user->can(PermissionName::PeriodsClose->value);
    }
}
