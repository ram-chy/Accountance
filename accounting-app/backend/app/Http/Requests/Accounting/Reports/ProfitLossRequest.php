<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for the profit and loss statement.
 *
 * Only the shared date window. There is no account or company filter: P&L is a
 * whole-company statement, and the sections are determined by account type, not
 * by the caller.
 */
class ProfitLossRequest extends ReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->dateRangeRules();
    }
}
