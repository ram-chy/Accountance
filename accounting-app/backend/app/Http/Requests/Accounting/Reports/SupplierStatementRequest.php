<?php

namespace App\Http\Requests\Accounting\Reports;

/**
 * Filters for a supplier statement.
 */
class SupplierStatementRequest extends ReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->dateRangeRules(), [
            'supplier_id' => $this->companySupplierRule(),
        ]);
    }

    public function supplierId(): int
    {
        return (int) $this->input('supplier_id');
    }
}
