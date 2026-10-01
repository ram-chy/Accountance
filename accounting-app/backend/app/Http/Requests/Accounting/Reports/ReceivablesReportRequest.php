<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for the outstanding receivables report.
 *
 * `customer_id` narrows the report to one customer and is optional; `as_of`
 * defaults to today in the accessor.
 */
class ReceivablesReportRequest extends ReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->asOfRules(), [
            'customer_id' => $this->companyCustomerRule(required: false),
        ]);
    }

    public function customerId(): ?int
    {
        return $this->filled('customer_id') ? (int) $this->input('customer_id') : null;
    }
}
