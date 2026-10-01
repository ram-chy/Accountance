<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StorePeriodRequest;
use App\Http\Requests\Accounting\UpdatePeriodRequest;
use App\Http\Resources\AccountingPeriodResource;
use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accounting period endpoints.
 *
 * There is no reopen route. Closing is one-way in Phase 4 and the absence is
 * deliberate: an endpoint to reopen a closed period would undo the only
 * protection a closed period provides.
 */
class AccountingPeriodController extends Controller
{
    public function __construct(
        private readonly AccountingPeriodService $periods,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AccountingPeriod::class);

        $company = $this->companyContext->getOrFail();

        $periods = AccountingPeriod::query()
            ->where('company_id', $company->getKey())
            ->when(
                $request->has('status'),
                fn ($query) => $query->where('status', $request->string('status')),
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
     * Close a period. One-way: no reopen endpoint exists in Phase 4.
     */
    public function close(AccountingPeriod $period): JsonResponse
    {
        $this->authorize('close', $period);

        $closed = $this->periods->close($period);

        return ApiResponse::success(
            message: 'Accounting period closed successfully.',
            data: new AccountingPeriodResource($closed),
        );
    }
}
