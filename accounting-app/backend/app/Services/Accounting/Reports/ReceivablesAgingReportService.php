<?php

namespace App\Services\Accounting\Reports;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Aged receivables.
 *
 * Delegates the outstanding set to ReceivablesReportService and only groups it,
 * so an invoice can never appear in one report and be missing from the other.
 */
class ReceivablesAgingReportService extends AgingReportService
{
    public function __construct(private readonly ReceivablesReportService $receivables) {}

    protected function outstandingRows(Company $company, ?int $counterpartyId, Carbon $asOf): array
    {
        return $this->receivables->generate($company, $counterpartyId, $asOf)['rows'];
    }

    protected function counterpartyKey(): string
    {
        return 'customer';
    }
}
