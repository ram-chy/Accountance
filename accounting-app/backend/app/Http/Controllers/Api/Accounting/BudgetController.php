<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Budgets\BudgetFilterRequest;
use App\Http\Requests\Accounting\Budgets\BudgetVarianceRequest;
use App\Http\Requests\Accounting\Budgets\ReviseBudgetRequest;
use App\Http\Requests\Accounting\Budgets\StoreBudgetLineRequest;
use App\Http\Requests\Accounting\Budgets\StoreBudgetRequest;
use App\Http\Requests\Accounting\Budgets\UpdateBudgetLineRequest;
use App\Http\Requests\Accounting\Budgets\UpdateBudgetRequest;
use App\Http\Resources\BudgetLineResource;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Services\Accounting\Budgets\BudgetLineService;
use App\Services\Accounting\Budgets\BudgetService;
use App\Services\Accounting\Budgets\BudgetVarianceReportService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Budget endpoints.
 *
 * A budget is a plan, not an accounting record: nothing here posts, and every
 * actual figure the variance endpoint returns is derived from posted journals at
 * request time. The controller is thin - it resolves the active company, delegates
 * to the budget services (where the draft-only and eligibility rules live) and
 * shapes the response.
 *
 * WHAT IS NOT A ROUTE
 *
 * There is no route that accepts a status, a version number, a parent id or an
 * approved-by. Approval is its own POST act with its own permission, and a
 * revision is its own POST act that creates a new draft; neither is reachable by
 * PUT. That separation is the same one journals and documents use, and it is what
 * makes "an approved budget is immutable" true through the API rather than merely
 * intended.
 *
 * LINE ROUTES
 *
 * Lines are addressed under their budget and authorize against it. A line has no
 * company_id of its own, so it is resolved through `$budget->lines()` rather than
 * a global route binding - a global binding by primary key would resolve another
 * company's line. A line id that is not this budget's therefore 404s.
 *
 * The route binding resolves `{budget}` scoped to the active company, so a budget
 * reaching a method here is already known to belong to it.
 */
class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetLineService $lines,
        private readonly BudgetVarianceReportService $variance,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(BudgetFilterRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $budgets = Budget::query()
            ->where('company_id', $company->getKey())
            ->when($request->filled('financial_year_id'), fn ($query) => $query->where('financial_year_id', $request->integer('financial_year_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->upper()->value()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term));
            })
            ->with('financialYear')
            ->withCount('lines')
            ->orderBy('code')
            ->orderBy('version_number')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Budgets retrieved successfully.',
            data: BudgetResource::collection($budgets),
        );
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $budget = $this->budgets->create(
            $this->companyContext->getOrFail(),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Budget created successfully.',
            data: new BudgetResource($this->withRelations($budget)),
            status: 201,
        );
    }

    public function show(Budget $budget): JsonResponse
    {
        $this->authorize('view', $budget);

        return ApiResponse::success(
            message: 'Budget retrieved successfully.',
            data: new BudgetResource($this->withRelations($budget)),
        );
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        $updated = $this->budgets->update(
            $budget,
            $this->companyContext->getOrFail(),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Budget updated successfully.',
            data: new BudgetResource($this->withRelations($updated)),
        );
    }

    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize('delete', $budget);

        $this->budgets->delete($budget, $this->companyContext->getOrFail(), $request->user());

        return ApiResponse::success(message: 'Budget deleted successfully.');
    }

    public function approve(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize('approve', $budget);

        $approved = $this->budgets->approve($budget, $this->companyContext->getOrFail(), $request->user());

        return ApiResponse::success(
            message: 'Budget approved successfully.',
            data: new BudgetResource($this->withRelations($approved)),
        );
    }

    public function revise(ReviseBudgetRequest $request, Budget $budget): JsonResponse
    {
        $revision = $this->budgets->revise(
            $budget,
            $this->companyContext->getOrFail(),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Budget revised successfully.',
            data: new BudgetResource($this->withRelations($revision)),
            status: 201,
        );
    }

    public function variance(BudgetVarianceRequest $request, Budget $budget): JsonResponse
    {
        $this->authorize('view', $budget);

        return ApiResponse::success(
            message: 'Budget variance report generated successfully.',
            data: $this->variance->generate(
                $budget,
                $this->companyContext->getOrFail(),
                $request->dimensionFilter(),
            ),
        );
    }

    public function storeLine(StoreBudgetLineRequest $request, Budget $budget): JsonResponse
    {
        $line = $this->lines->create(
            $budget,
            $this->companyContext->getOrFail(),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Budget line created successfully.',
            data: new BudgetLineResource($line->load('account', 'accountingPeriod', 'budgetLineDimensions')),
            status: 201,
        );
    }

    public function updateLine(UpdateBudgetLineRequest $request, Budget $budget, int $line): JsonResponse
    {
        $model = $this->resolveLine($budget, $line);

        $updated = $this->lines->update(
            $model,
            $budget,
            $this->companyContext->getOrFail(),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Budget line updated successfully.',
            data: new BudgetLineResource($updated->load('account', 'accountingPeriod', 'budgetLineDimensions')),
        );
    }

    public function destroyLine(Request $request, Budget $budget, int $line): JsonResponse
    {
        $this->authorize('update', $budget);

        $model = $this->resolveLine($budget, $line);

        $this->lines->delete($model, $budget, $this->companyContext->getOrFail(), $request->user());

        return ApiResponse::success(message: 'Budget line deleted successfully.');
    }

    /**
     * Resolve a line through its budget, so a line id from another budget or
     * company 404s rather than being reachable.
     */
    private function resolveLine(Budget $budget, int $line): BudgetLine
    {
        return $budget->lines()->whereKey($line)->firstOrFail();
    }

    private function withRelations(Budget $budget): Budget
    {
        return $budget
            ->load('financialYear')
            ->load('lines.account', 'lines.accountingPeriod', 'lines.budgetLineDimensions')
            ->loadCount('lines');
    }
}
