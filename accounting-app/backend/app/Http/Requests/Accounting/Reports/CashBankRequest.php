<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for the cash and bank report.
 *
 * One account is required, scoped to the active company. The report is not
 * restricted to asset accounts at the validation layer - a payment account could
 * in principle be any account, and refusing a liability-typed one here would be
 * a rule this phase has no basis for. The controller documents that the report
 * is intended for cash/bank accounts and returns the general-ledger shape.
 */
class CashBankRequest extends ReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->dateRangeRules(), [
            'account_id' => $this->companyAccountRule(),
        ]);
    }

    public function accountId(): int
    {
        return (int) $this->input('account_id');
    }
}
