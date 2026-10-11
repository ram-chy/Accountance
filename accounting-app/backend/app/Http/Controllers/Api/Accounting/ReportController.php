<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Reports\BalanceSheetRequest;
use App\Http\Requests\Accounting\Reports\CashBankRequest;
use App\Http\Requests\Accounting\Reports\CustomerStatementRequest;
use App\Http\Requests\Accounting\Reports\GeneralLedgerRequest;
use App\Http\Requests\Accounting\Reports\PayablesAgingRequest;
use App\Http\Requests\Accounting\Reports\PayablesReportRequest;
use App\Http\Requests\Accounting\Reports\ProfitLossRequest;
use App\Http\Requests\Accounting\Reports\ReceivablesAgingRequest;
use App\Http\Requests\Accounting\Reports\ReceivablesReportRequest;
use App\Http\Requests\Accounting\Reports\SupplierStatementRequest;
use App\Http\Requests\Accounting\Reports\TrialBalanceRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Accounting\Reports\BalanceSheetReportService;
use App\Services\Accounting\Reports\CashBankReportService;
use App\Services\Accounting\Reports\CustomerStatementReportService;
use App\Services\Accounting\Reports\GeneralLedgerReportService;
use App\Services\Accounting\Reports\PayablesAgingReportService;
use App\Services\Accounting\Reports\PayablesReportService;
use App\Services\Accounting\Reports\ProfitLossReportService;
use App\Services\Accounting\Reports\ReceivablesAgingReportService;
use App\Services\Accounting\Reports\ReceivablesReportService;
use App\Services\Accounting\Reports\SupplierStatementReportService;
use App\Services\Accounting\Reports\TrialBalanceReportService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Read-only reporting endpoints.
 *
 * There is no write path in this class and none in any service it calls: every
 * figure is derived at request time from posted journals or Phase 5 documents.
 * No report result is persisted, so there is no snapshot table whose staleness
 * could ever disagree with the ledger.
 *
 * Responses are plain arrays wrapped by ApiResponse, matching LedgerController,
 * rather than dedicated Resource classes. The Phase 4 precedent is deliberate:
 * a report's shape is defined by its service, and a pass-through Resource would
 * only restate it in a second place.
 *
 * Every action authorizes against the single `accounting.reports.view`
 * permission. There are no per-report permissions, because every report is a
 * read of the same accounting truth and every one is granted or withheld to the
 * same roles.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TrialBalanceReportService $trialBalance,
        private readonly GeneralLedgerReportService $generalLedger,
        private readonly ProfitLossReportService $profitLoss,
        private readonly BalanceSheetReportService $balanceSheet,
        private readonly CustomerStatementReportService $customerStatement,
        private readonly SupplierStatementReportService $supplierStatement,
        private readonly ReceivablesReportService $receivables,
        private readonly PayablesReportService $payables,
        private readonly ReceivablesAgingReportService $receivablesAging,
        private readonly PayablesAgingReportService $payablesAging,
        private readonly CashBankReportService $cashBank,
    ) {}

    public function trialBalance(TrialBalanceRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        $data = $this->trialBalance->generate(
            $this->companyContext->getOrFail(),
            $request->fromDate(),
            $request->toDate(),
            $request->includeZeroBalances(),
        );

        return ApiResponse::success('Trial balance generated successfully.', $data);
    }

    public function generalLedger(GeneralLedgerRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        $data = $this->generalLedger->generate(
            $this->companyContext->getOrFail(),
            $this->resolveAccount($request->accountId()),
            $request->fromDate(),
            $request->toDate(),
        );

        return ApiResponse::success('General ledger generated successfully.', $data);
    }

    public function profitLoss(ProfitLossRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        return ApiResponse::success(
            'Profit and loss statement generated successfully.',
            $this->profitLoss->generate(
                $this->companyContext->getOrFail(),
                $request->fromDate(),
                $request->toDate(),
                $request->dimensionFilter(),
            )
        );
    }

    public function balanceSheet(BalanceSheetRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        return ApiResponse::success(
            'Balance sheet generated successfully.',
            $this->balanceSheet->generate(
                $this->companyContext->getOrFail(),
                $request->fromDate(),
                $request->toDate(),
            )
        );
    }

    public function customerStatement(CustomerStatementRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        $company = $this->companyContext->getOrFail();
        $customer = Customer::query()->where('company_id', $company->getKey())->findOrFail($request->customerId());

        return ApiResponse::success(
            'Customer statement generated successfully.',
            $this->customerStatement->generate($company, $customer, $request->fromDate(), $request->toDate())
        );
    }

    public function supplierStatement(SupplierStatementRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        $company = $this->companyContext->getOrFail();
        $supplier = Supplier::query()->where('company_id', $company->getKey())->findOrFail($request->supplierId());

        return ApiResponse::success(
            'Supplier statement generated successfully.',
            $this->supplierStatement->generate($company, $supplier, $request->fromDate(), $request->toDate())
        );
    }

    public function receivables(ReceivablesReportRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        return ApiResponse::success(
            'Receivables report generated successfully.',
            $this->receivables->generate($this->companyContext->getOrFail(), $request->customerId(), $request->asOfDate())
        );
    }

    public function payables(PayablesReportRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        return ApiResponse::success(
            'Payables report generated successfully.',
            $this->payables->generate($this->companyContext->getOrFail(), $request->supplierId(), $request->asOfDate())
        );
    }

    public function receivablesAging(ReceivablesAgingRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        return ApiResponse::success(
            'Aged receivables generated successfully.',
            $this->receivablesAging->generate($this->companyContext->getOrFail(), $request->customerId(), $request->asOfDate())
        );
    }

    public function payablesAging(PayablesAgingRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        return ApiResponse::success(
            'Aged payables generated successfully.',
            $this->payablesAging->generate($this->companyContext->getOrFail(), $request->supplierId(), $request->asOfDate())
        );
    }

    public function cashBank(CashBankRequest $request): JsonResponse
    {
        $this->authorizeReporting();

        return ApiResponse::success(
            'Cash and bank report generated successfully.',
            $this->cashBank->generate(
                $this->companyContext->getOrFail(),
                $this->resolveAccount($request->accountId()),
                $request->fromDate(),
                $request->toDate(),
            )
        );
    }

    private function authorizeReporting(): void
    {
        $this->authorize(PermissionName::ReportsView->value);
    }

    /**
     * Resolve an account through the active company.
     *
     * Route model binding does not see a query-string id, so this is the filter
     * that actually enforces tenancy: a cross-company id yields a 404 rather than
     * another company's ledger. The request rule already scopes it, and repeating
     * the scope here means a future request class that forgets cannot expose the
     * data.
     */
    private function resolveAccount(int $accountId): Account
    {
        return Account::query()
            ->where('company_id', $this->companyContext->getOrFail()->getKey())
            ->findOrFail($accountId);
    }
}
