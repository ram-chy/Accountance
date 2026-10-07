<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StorePeriodRequest;
use App\Http\Requests\Accounting\UpdatePeriodRequest;
use App\Http\Resources\AccountingPeriodResource;
use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\PeriodClosingCheckService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accounting-period endpoints.
 *
 * Phase 8 adds reopen. Phase 4 had no such route because it had no reopen
 * permission to gate one with, and it recorded the consequence in its report: a
 * period closed in error needed a database-level correction. Reopen is now an
 * explicit, separately-authorized act rather than anything implied by close.
 * Undoing an accounting-control decision is a larger grant than making one, so a
 * role that may close a period does not thereby gain the ability to reopen it.
 */
class AccountingPeriodController extends Controller
{
    public function __construct(
        private readonly AccountingPeriodService $periods,
        private readonly PeriodClosingCheckService $closingCheck,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AccountingPeriod::class);

        $company = $this->companyContext->getOrFail();

        $periods = AccountingPeriod::query()
            ->with('financialYear')
            ->where('company_id', $company->getKey())
            ->when(
                $request->has('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when(
                $request->has('financial_year_id'),
                fn ($query) => $query->where('financial_year_id', $request->integer('financial_year_id')),
            )
            /*
             * from_date/to_date answer "which periods cover this range", which is a
             * question a calendar screen asks. They select on the period *range* rather
             * than the period's own date: a filter matching start_date alone would drop
             * the period that began before from_date but still covers it, which is the
             * opposite of what the caller wants. Two bounds only - no generic filter
             * DSL, which the phase rules out explicitly.
             */
            ->when(
                $request->has('from_date'),
                fn ($query) => $query->whereDate('end_date', '>=', $request->date('from_date')->toDateString()),
            )
            ->when(
                $request->has('to_date'),
                fn ($query) => $query->whereDate('start_date', '<=', $request->date('to_date')->toDateString()),
            )
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Accounting periods retrieved successfully.',
            data: AccountingPeriodResource::collection($periods),
        );
    }

    public function store(StorePeriodRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $period = $this->periods->create($company, $request->validated());

        return ApiResponse::success(
            message: 'Accounting period created successfully.',
            data: new AccountingPeriodResource($period),
            status: 201,
        );
    }

    public function show(AccountingPeriod $period): JsonResponse
    {
        $this->authorize('view', $period);

        $period->load('financialYear');

        return ApiResponse::success(
            message: 'Accounting period retrieved successfully.',
            data: new AccountingPeriodResource($period),
        );
    }

    public function update(UpdatePeriodRequest $request, AccountingPeriod $period): JsonResponse
    {
        $updated = $this->periods->update($period, $request->validated());

        return ApiResponse::success(
            message: 'Accounting period updated successfully.',
            data: new AccountingPeriodResource($updated),
        );
    }

    /**
     * Read-only period-end review.
     *
     * Answers "may this period be closed, and if not, why" without closing
     * anything. It rides on the view permission rather than close: reading the
     * state of a period one may not close is not itself a control act, and a
     * user who can see the period already has the right to know what would block
     * its closure.
     */
    public function closingCheck(AccountingPeriod $period): JsonResponse
    {
        $this->authorize('view', $period);

        $review = $this->closingCheck->review($period->company, $period);

        return ApiResponse::success(
            message: 'Period closing review evaluated successfully.',
            data: $review,
        );
    }

    /**
     * Close a period. Records who closed it and when; posting into it is refused
     * from the moment the transaction commits.
     */
    public function close(Request $request, AccountingPeriod $period): JsonResponse
    {
        $this->authorize('close', $period);

        $closed = $this->periods->close($period, $request->user());

        return ApiResponse::success(
            message: 'Accounting period closed successfully.',
            data: new AccountingPeriodResource($closed),
        );
    }

    /**
     * Reopen a closed period.
     *
     * Does not touch journal history. The entries posted before the close were
     * legitimate then and remain so; what changes is only whether new postings are
     * accepted for the period's dates.
     */
    public function reopen(Request $request, AccountingPeriod $period): JsonResponse
    {
        $this->authorize('reopen', $period);

        $reopened = $this->periods->reopen($period, $request->user());

        return ApiResponse::success(
            message: 'Accounting period reopened successfully.',
            data: new AccountingPeriodResource($reopened),
        );
    }
}
