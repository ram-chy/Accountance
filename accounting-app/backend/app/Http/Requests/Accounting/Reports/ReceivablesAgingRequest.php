<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for aged receivables.
 *
 * Same filters as the receivables report; the aging view is the same documents
 * grouped into buckets.
 */
class ReceivablesAgingRequest extends ReportRequest
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
