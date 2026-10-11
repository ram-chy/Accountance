<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Dimensions\FinancialDimensionFilterRequest;
use App\Http\Requests\Accounting\Dimensions\FinancialDimensionValueFilterRequest;
use App\Http\Requests\Accounting\Dimensions\StoreFinancialDimensionRequest;
use App\Http\Requests\Accounting\Dimensions\StoreFinancialDimensionValueRequest;
use App\Http\Requests\Accounting\Dimensions\UpdateFinancialDimensionRequest;
use App\Http\Requests\Accounting\Dimensions\UpdateFinancialDimensionValueRequest;
use App\Http\Resources\FinancialDimensionResource;
use App\Http\Resources\FinancialDimensionValueResource;
use App\Models\FinancialDimension;
use App\Models\FinancialDimensionValue;
use App\Services\Accounting\Dimensions\FinancialDimensionService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financial dimension endpoints - the cost-centre master data of Phase 17.
 *
 * The controller is thin on purpose: the route binding resolves a dimension
 * already scoped to the active company (a foreign id 404s before any method here
 * runs), the requests authorize against the policy, and every write is delegated
 * to FinancialDimensionService where the duplicate-code, lifecycle and reference
 * rules live.
 *
 * VALUES ARE ADDRESSED THROUGH THEIR DIMENSION, ALWAYS.
 *
 * There is no `/dimension-values/{value}` route. A value has no company_id of its
 * own, so a binding by primary key could resolve another company's row; resolving
 * it through `$dimension->values()` gives the nesting check and the tenant check in
 * one query, and a value id from a different dimension 404s rather than being
 * reachable. `activate`/`deactivate` are POST because they are acts with their own
 * guards rather than a state to PUT wholesale, following the accounts, taxes and
 * fixed-asset category precedent.
 */
class FinancialDimensionController extends Controller
{
    public function __construct(
        private readonly FinancialDimensionService $dimensions,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(FinancialDimensionFilterRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $dimensions = FinancialDimension::query()
            ->where('company_id', $company->getKey())
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term));
            })
            ->withCount('values')
            ->orderBy('type')
            ->orderBy('code')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Financial dimensions retrieved successfully.',
            data: FinancialDimensionResource::collection($dimensions),
        );
    }

    public function store(StoreFinancialDimensionRequest $request): JsonResponse
    {
        $dimension = $this->dimensions->create(
            $this->companyContext->getOrFail(),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Financial dimension created successfully.',
            data: new FinancialDimensionResource($dimension),
            status: 201,
        );
    }

    public function show(FinancialDimension $dimension): JsonResponse
    {
        $this->authorize('view', $dimension);

        return ApiResponse::success(
            message: 'Financial dimension retrieved successfully.',
            data: new FinancialDimensionResource($dimension->load('values')),
        );
    }

    public function update(UpdateFinancialDimensionRequest $request, FinancialDimension $dimension): JsonResponse
    {
        $updated = $this->dimensions->update($dimension, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Financial dimension updated successfully.',
            data: new FinancialDimensionResource($updated),
        );
    }

    public function destroy(Request $request, FinancialDimension $dimension): JsonResponse
    {
        $this->authorize('delete', $dimension);

        $this->dimensions->delete($dimension, $request->user());

        return ApiResponse::success(message: 'Financial dimension deleted successfully.');
    }

    public function activate(Request $request, FinancialDimension $dimension): JsonResponse
    {
        $this->authorize('update', $dimension);

        $activated = $this->dimensions->activate($dimension, $request->user());

        return ApiResponse::success(
            message: 'Financial dimension activated successfully.',
            data: new FinancialDimensionResource($activated),
        );
    }

    public function deactivate(Request $request, FinancialDimension $dimension): JsonResponse
    {
        $this->authorize('update', $dimension);

        $deactivated = $this->dimensions->deactivate($dimension, $request->user());

        return ApiResponse::success(
            message: 'Financial dimension deactivated successfully.',
            data: new FinancialDimensionResource($deactivated),
        );
    }

    public function indexValues(FinancialDimensionValueFilterRequest $request, FinancialDimension $dimension): JsonResponse
    {
        $values = $dimension->values()
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term));
            })
            ->orderBy('code')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Financial dimension values retrieved successfully.',
            data: FinancialDimensionValueResource::collection($values),
        );
    }

    public function storeValue(StoreFinancialDimensionValueRequest $request, FinancialDimension $dimension): JsonResponse
    {
        $value = $this->dimensions->createValue($dimension, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Financial dimension value created successfully.',
            data: new FinancialDimensionValueResource($value),
            status: 201,
        );
    }

    public function showValue(FinancialDimension $dimension, string $value): JsonResponse
    {
        $this->authorize('view', $dimension);

        return ApiResponse::success(
            message: 'Financial dimension value retrieved successfully.',
            data: new FinancialDimensionValueResource($this->resolveValue($dimension, $value)),
        );
    }

    public function updateValue(
        UpdateFinancialDimensionValueRequest $request,
        FinancialDimension $dimension,
        string $value,
    ): JsonResponse {
        $updated = $this->dimensions->updateValue(
            $this->resolveValue($dimension, $value),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Financial dimension value updated successfully.',
            data: new FinancialDimensionValueResource($updated),
        );
    }

    public function destroyValue(Request $request, FinancialDimension $dimension, string $value): JsonResponse
    {
        $this->authorize('delete', $dimension);

        $this->dimensions->deleteValue($this->resolveValue($dimension, $value), $request->user());

        return ApiResponse::success(message: 'Financial dimension value deleted successfully.');
    }

    public function activateValue(Request $request, FinancialDimension $dimension, string $value): JsonResponse
    {
        $this->authorize('update', $dimension);

        $activated = $this->dimensions->activateValue($this->resolveValue($dimension, $value), $request->user());

        return ApiResponse::success(
            message: 'Financial dimension value activated successfully.',
            data: new FinancialDimensionValueResource($activated),
        );
    }

    public function deactivateValue(Request $request, FinancialDimension $dimension, string $value): JsonResponse
    {
        $this->authorize('update', $dimension);

        $deactivated = $this->dimensions->deactivateValue($this->resolveValue($dimension, $value), $request->user());

        return ApiResponse::success(
            message: 'Financial dimension value deactivated successfully.',
            data: new FinancialDimensionValueResource($deactivated),
        );
    }

    /**
     * Resolve a value through its dimension.
     *
     * The nesting is the security check: a value id belonging to another dimension
     * - which may belong to another company entirely - is simply not in this
     * dimension's own set, so the lookup 404s instead of handing the controller
     * somebody else's row.
     */
    private function resolveValue(FinancialDimension $dimension, string $value): FinancialDimensionValue
    {
        return $dimension->values()->whereKey($value)->firstOrFail();
    }
}
