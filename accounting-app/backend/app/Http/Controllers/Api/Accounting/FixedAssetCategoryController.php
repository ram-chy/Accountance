<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FixedAssets\FixedAssetCategoryFilterRequest;
use App\Http\Requests\Accounting\FixedAssets\StoreFixedAssetCategoryRequest;
use App\Http\Requests\Accounting\FixedAssets\UpdateFixedAssetCategoryRequest;
use App\Http\Resources\FixedAssetCategoryResource;
use App\Models\FixedAssetCategory;
use App\Services\Accounting\FixedAssets\FixedAssetCategoryService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fixed asset category endpoints.
 *
 * A category is the template a new asset copies its five accounts and useful life
 * from. The controller is therefore thin: it resolves the active company, delegates
 * the write to FixedAssetCategoryService - where the account eligibility rules and the
 * "at least one disposal account" rule live - and shapes the response.
 *
 * `activate` and `deactivate` are POST because they are acts with their own guards,
 * not a state to be PUT wholesale, following the taxes and accounts precedent. The
 * deactivate path is the one that is more than a flag flip: the service refuses it
 * while any asset still refers to the category.
 *
 * The route binding resolves a FixedAssetCategory scoped to the active company, so a
 * category reaching a method here is already known to belong to it and a foreign id
 * has 404ed before this class runs. The company the service is handed comes from the
 * context, never from the request.
 */
class FixedAssetCategoryController extends Controller
{
    public function __construct(
        private readonly FixedAssetCategoryService $categories,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(FixedAssetCategoryFilterRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $categories = FixedAssetCategory::query()
            ->where('company_id', $company->getKey())
            /*
             * `is_active` is a three-state filter: absent means both, which is why the
             * check is has() rather than a boolean cast that would turn an absent key
             * into false and silently show only retired categories.
             */
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term));
            })
            ->with($this->accountRelations())
            ->withCount('assets')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Fixed asset categories retrieved successfully.',
            data: FixedAssetCategoryResource::collection($categories),
        );
    }

    public function store(StoreFixedAssetCategoryRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $category = $this->categories->create($company, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Fixed asset category created successfully.',
            data: new FixedAssetCategoryResource($this->withRelations($category)),
            status: 201,
        );
    }

    public function show(FixedAssetCategory $fixedAssetCategory): JsonResponse
    {
        $this->authorize('view', $fixedAssetCategory);

        return ApiResponse::success(
            message: 'Fixed asset category retrieved successfully.',
            data: new FixedAssetCategoryResource($this->withRelations($fixedAssetCategory)),
        );
    }

    public function update(UpdateFixedAssetCategoryRequest $request, FixedAssetCategory $fixedAssetCategory): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $updated = $this->categories->update(
            $fixedAssetCategory,
            $company,
            $request->user(),
            $request->validated()
        );

        return ApiResponse::success(
            message: 'Fixed asset category updated successfully.',
            data: new FixedAssetCategoryResource($this->withRelations($updated)),
        );
    }

    public function destroy(Request $request, FixedAssetCategory $fixedAssetCategory): JsonResponse
    {
        $this->authorize('delete', $fixedAssetCategory);

        $this->categories->delete($fixedAssetCategory, $request->user());

        return ApiResponse::success(message: 'Fixed asset category deleted successfully.');
    }

    public function activate(Request $request, FixedAssetCategory $fixedAssetCategory): JsonResponse
    {
        $this->authorize('update', $fixedAssetCategory);

        $activated = $this->categories->activate($fixedAssetCategory, $request->user());

        return ApiResponse::success(
            message: 'Fixed asset category activated successfully.',
            data: new FixedAssetCategoryResource($this->withRelations($activated)),
        );
    }

    public function deactivate(Request $request, FixedAssetCategory $fixedAssetCategory): JsonResponse
    {
        $this->authorize('update', $fixedAssetCategory);

        $deactivated = $this->categories->deactivate($fixedAssetCategory, $request->user());

        return ApiResponse::success(
            message: 'Fixed asset category deactivated successfully.',
            data: new FixedAssetCategoryResource($this->withRelations($deactivated)),
        );
    }

    /**
     * The five account relations every category response renders.
     *
     * @return array<int, string>
     */
    private function accountRelations(): array
    {
        return [
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'gainOnDisposalAccount',
            'lossOnDisposalAccount',
        ];
    }

    private function withRelations(FixedAssetCategory $category): FixedAssetCategory
    {
        return $category->load($this->accountRelations())->loadCount('assets');
    }
}
