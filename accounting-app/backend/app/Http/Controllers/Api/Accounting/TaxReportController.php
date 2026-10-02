<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Reports\TaxReportRequest;
use App\Services\Accounting\Reports\TaxReportService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Read-only reporting on what the company collected and recovered as tax.
 *
 * A separate controller from the Phase 6 ReportController on purpose. That class
 * states in its own docblock that every action it holds is gated on
 * `accounting.reports.view`; adding two actions with a different permission would
 * make that statement false and quietly weaken the guarantee it documents. The
 * split keeps the Phase 6 claim true and puts the tax reports' separate permission
 * in the class that owns them.
 *
 * Neither action writes, and neither is a calculation: `TaxReportService` has no
 * write on its path and recomputes no tax. Amounts come from posted documents'
 * own line snapshots, so a rate changed after the fact cannot alter what a past
 * period reported.
 */
class TaxReportController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TaxReportService $taxReports,
    ) {}

    /**
     * Collected, recovered and net tax for the period.
     */
    public function summary(TaxReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            'Tax summary report generated successfully.',
            $this->taxReports->summary(
                $this->companyContext->getOrFail(),
                $request->fromDate(),
                $request->toDate(),
            ),
        );
    }

    /**
     * The same figures, one row per configured tax.
     */
    public function byTax(TaxReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            'Tax by-tax report generated successfully.',
            $this->taxReports->byTax(
                $this->companyContext->getOrFail(),
                $request->fromDate(),
                $request->toDate(),
            ),
        );
    }
}
