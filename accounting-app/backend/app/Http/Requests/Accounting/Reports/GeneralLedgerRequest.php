<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for a single account's general ledger.
 *
 * The account id is validated against the active company, not merely against
 * the accounts table, so a caller cannot read another company's ledger by
 * guessing an id.
 */
class GeneralLedgerRequest extends ReportRequest
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
