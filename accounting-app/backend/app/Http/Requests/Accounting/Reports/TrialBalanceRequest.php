<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for the trial balance.
 *
 * No account or company filter: a trial balance is a whole-company statement,
 * and `include_zero_balances` is the only presentation switch.
 */
class TrialBalanceRequest extends ReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->dateRangeRules(), [
            'include_zero_balances' => ['sometimes', 'boolean'],
        ]);
    }

    public function includeZeroBalances(): bool
    {
        return $this->boolean('include_zero_balances');
    }
}
