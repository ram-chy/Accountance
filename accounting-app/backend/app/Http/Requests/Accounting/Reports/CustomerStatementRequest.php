<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for a customer statement.
 */
class CustomerStatementRequest extends ReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->dateRangeRules(), [
            'customer_id' => $this->companyCustomerRule(),
        ]);
    }

    public function customerId(): int
    {
        return (int) $this->input('customer_id');
    }
}
