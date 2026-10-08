<?php

namespace App\Http\Requests\Accounting\Budgets;

use App\Http\Requests\Transactions\ValidatesTransactionAmounts;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Validation\Rule;

/**
 * Shared rules for the budget request classes.
 *
 * Builds on the transaction-amount trait so a planned amount is subject to the
 * same decimal-input rule and the same "never a PHP float" promise as every other
 * money value in the application. What it adds is the two company-scoped
 * references a budget line carries - an account and an accounting period - both
 * of which must resolve inside the ACTIVE company.
 *
 * Scoping here is necessary but not sufficient. A query-string or body id is not
 * seen by route model binding, so `exists` alone would accept an id from any
 * company; the `where company_id` constraint is what makes it belong. The
 * service then re-applies ownership AND the type/period rules that `exists`
 * cannot express (a line is Revenue/Expense only, and the period must be in the
 * budget's year).
 *
 * activeCompanyId() is implemented once here rather than in each request: every
 * budget endpoint resolves the company from the authenticated context and never
 * from the payload, and repeating that in six files would be six places for it
 * to drift.
 */
trait ValidatesBudgetInputs
{
    use ValidatesTransactionAmounts;

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }

    /**
     * A required company account, on a partial update.
     *
     * @return array<int, mixed>
     */
    protected function sometimesCompanyAccountRule(): array
    {
        return array_merge(['sometimes'], $this->companyAccountRule());
    }

    /**
     * A required accounting period that belongs to the active company.
     *
     * @return array<int, mixed>
     */
    protected function companyPeriodRule(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('accounting_periods', 'id')->where('company_id', $this->activeCompanyId()),
        ];
    }

    /**
     * The same rule, guarded so an omitted period on an update is not treated as
     * absent-but-required.
     *
     * @return array<int, mixed>
     */
    protected function sometimesCompanyPeriodRule(): array
    {
        return array_merge(['sometimes'], $this->companyPeriodRule());
    }

    /**
     * A financial year that belongs to the active company.
     *
     * @return array<int, mixed>
     */
    protected function companyFinancialYearRule(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('financial_years', 'id')->where('company_id', $this->activeCompanyId()),
        ];
    }
}
