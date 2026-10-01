<?php

namespace App\Services\Accounting\Reports;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Aged payables.
 *
 * Delegates the outstanding set to PayablesReportService and only groups it.
 */
class PayablesAgingReportService extends AgingReportService
{
    public function __construct(private readonly PayablesReportService $payables) {}

    protected function outstandingRows(Company $company, ?int $counterpartyId, Carbon $asOf): array
    {
        return $this->payables->generate($company, $counterpartyId, $asOf)['rows'];
    }

    protected function counterpartyKey(): string
    {
        return 'supplier';
    }
}
