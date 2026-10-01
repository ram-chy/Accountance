<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for the outstanding payables report.
 */
class PayablesReportRequest extends ReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->asOfRules(), [
            'supplier_id' => $this->companySupplierRule(required: false),
        ]);
    }

    public function supplierId(): ?int
    {
        return $this->filled('supplier_id') ? (int) $this->input('supplier_id') : null;
    }
}
