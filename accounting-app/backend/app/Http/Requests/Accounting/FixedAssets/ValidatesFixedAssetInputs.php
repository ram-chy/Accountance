<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use App\Http\Requests\Transactions\ValidatesTransactionAmounts;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Validation\Rule;

/**
 * Shared rules for the fixed asset request classes.
 *
 * Builds on the transaction-amount trait, which is where the decimal-input and
 * company-scoped account rules actually live - reusing it rather than restating
 * "a money value must arrive as a decimal string" is what keeps a fixed asset cost
 * subject to the same tolerance and the same rounding promise as an invoice total.
 *
 * What this trait adds is the one rule the transactions module never needed: an
 * OPTIONAL company account. The gain-on-disposal and loss-on-disposal accounts may be
 * absent - a company can be configured to handle only one direction - so they cannot
 * use the required companyAccountRule(). The two spellings are kept side by side
 * here so the difference between them is one word and not an oversight.
 *
 * activeCompanyId() is implemented here rather than in each request: every fixed
 * asset endpoint resolves the company the same way, from the authenticated context
 * and never from the payload, and repeating that in nine files would be nine places
 * for it to drift.
 */
trait ValidatesFixedAssetInputs
{
    use ValidatesTransactionAmounts;

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }

    /**
     * A required company account on a partial update.
     *
     * companyAccountRule() begins with `required`, which is one of the few rules
     * Laravel applies even when the field is absent - so using it bare on an update
     * would make every omitted account a validation failure. `sometimes` guards it:
     * present means it must carry an id, absent means leave the stored value alone.
     *
     * @return array<int, mixed>
     */
    protected function sometimesCompanyAccountRule(): array
    {
        return array_merge(['sometimes'], $this->companyAccountRule());
    }

    /**
     * An account id that may be absent but, if present, must be in the active
     * company.
     *
     * @return array<int, mixed>
     */
    protected function nullableCompanyAccountRule(): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
        ];
    }
}
