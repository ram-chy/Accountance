<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for the balance sheet.
 *
 * `to_date` is the as-of moment and defaults to today. `from_date` is optional
 * and only labels the informational current-period result; the section balances
 * are always cumulative through `to_date`.
 */
class BalanceSheetRequest extends ReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->dateRangeRules();
    }
}
