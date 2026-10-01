<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreFinancialYearRequest;
use App\Http\Requests\Accounting\UpdateFinancialYearRequest;
use App\Http\Resources\FinancialYearResource;
use App\Models\FinancialYear;
use App\Services\Accounting\FinancialYearService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financial-year management.
 *
 * Company isolation is not re-implemented here. The route binding on
 * `financialYear` resolves within the authenticated company, so a year reaching
 * any method of this controller already belongs to it, and a foreign id 404s
 * before the controller runs. Nothing here reads company_id from the request.
 *
 * Authorization rides on AccountingPeriodPolicy's year methods, deliberately: the
 * permission set is shared with periods, so a separate policy would be a second
 * place to keep the same two answers in step.
 */
class FinancialYearController extends Controller
{
    public function __construct(
        private readonly FinancialYearService $years,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAnyYear', FinancialYear::class);

        $company = $this->companyContext->getOrFail();

        $years = FinancialYear::query()
            ->where('company_id', $company->getKey())
            ->when(
                $request->has('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when(
                $request->has('period_status'),
                // Filter by a period's state without joining: the year is the
                // parent, so this answers "years that still have an open month",
                // which is the question a user has when deciding what to close.
                fn ($query) => $query->whereHas(
                    'periods',
                    fn ($periods) => $periods->where('status', $request->string('period_status')),
                ),
            )
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Financial years retrieved successfully.',
            data: FinancialYearResource::collection($years),
        );
    }

    public function store(StoreFinancialYearRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $year = $this->years->create($company, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Financial year created successfully.',
            data: new FinancialYearResource($year),
            status: 201,
        );
    }

    public function show(FinancialYear $financialYear): JsonResponse
    {
        $this->authorize('viewYear', $financialYear);

        $financialYear->loadCount([
            'periods as periods_count',
            'periods as open_periods_count' => fn ($query) => $query->where('status', 'OPEN'),
        ]);

        return ApiResponse::success(
            message: 'Financial year retrieved successfully.',
            data: new FinancialYearResource($financialYear),
        );
    }

    public function update(UpdateFinancialYearRequest $request, FinancialYear $financialYear): JsonResponse
    {
        $updated = $this->years->update($financialYear, $request->validated());

        return ApiResponse::success(
            message: 'Financial year updated successfully.',
            data: new FinancialYearResource($updated),
        );
    }

    /**
     * Generate the monthly accounting periods of a financial year.
     *
     * Idempotent: calling it again over a generated year creates nothing. The
     * response reports what the year holds afterwards rather than what this call
     * added, because on a repeat call those differ and reporting the delta would
     * suggest nothing happened when in fact the year is fully set up.
     */
    public function generatePeriods(FinancialYear $financialYear): JsonResponse
    {
        $this->authorize('generatePeriods', $financialYear);

        $periods = $this->years->generatePeriods($financialYear);

        $financialYear->refresh()->loadCount([
            'periods as periods_count',
            'periods as open_periods_count' => fn ($query) => $query->where('status', 'OPEN'),
        ]);

        return ApiResponse::success(
            message: 'Accounting periods generated successfully.',
            data: [
                'financial_year' => new FinancialYearResource($financialYear),
                'periods_count' => count($periods),
            ],
            status: 201,
        );
    }

    /**
     * Close a financial year.
     *
     * Permitted only once every period of the year is closed. Writes no journal -
     * see FinancialYearService::close() for why retained earnings is derived
     * rather than posted.
     */
    public function close(Request $request, FinancialYear $financialYear): JsonResponse
    {
        $this->authorize('closeYear', $financialYear);

        $closed = $this->years->close($financialYear, $request->user());

        return ApiResponse::success(
            message: 'Financial year closed successfully.',
            data: new FinancialYearResource($closed),
        );
    }
}
