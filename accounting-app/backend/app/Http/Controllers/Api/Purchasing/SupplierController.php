<?php

namespace App\Http\Controllers\Api\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\DeactivateSupplierRequest;
use App\Http\Requests\Transactions\StoreSupplierRequest;
use App\Http\Requests\Transactions\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\CompanyContext;
use App\Services\Purchasing\SupplierService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supplier endpoints.
 *
 * The counterpart of CustomerController, with the same three deliberate choices:
 * no DELETE, no reactivate, and a company that comes from CompanyContext on every
 * path rather than from the route or the body.
 */
class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $company = $this->companyContext->getOrFail();

        $suppliers = Supplier::query()
            ->where('company_id', $company->getKey())
            ->when(
                $request->has('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('supplier_code', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('tax_identifier', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Suppliers retrieved successfully.',
            data: SupplierResource::collection($suppliers),
        );
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $supplier = $this->suppliers->create($company, $request->validated());

        return ApiResponse::success(
            message: 'Supplier created successfully.',
            data: new SupplierResource($supplier),
            status: 201,
        );
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        return ApiResponse::success(
            message: 'Supplier retrieved successfully.',
            data: new SupplierResource($supplier),
        );
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier = $this->suppliers->update($supplier, $request->validated());

        return ApiResponse::success(
            message: 'Supplier updated successfully.',
            data: new SupplierResource($supplier),
        );
    }

    public function deactivate(DeactivateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier = $this->suppliers->deactivate($supplier);

        return ApiResponse::success(
            message: 'Supplier deactivated successfully.',
            data: new SupplierResource($supplier),
        );
    }
}
