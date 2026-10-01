<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\DeactivateCustomerRequest;
use App\Http\Requests\Transactions\StoreCustomerRequest;
use App\Http\Requests\Transactions\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CompanyContext;
use App\Services\Sales\CustomerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer endpoints.
 *
 * There is no DELETE and no reactivate. Deactivation is a POST, because it is a
 * state change with a permission of its own, not a partial update - and hard
 * deletion is unavailable for any customer that has ever appeared on an invoice,
 * since the document must stay resolvable to say who it was raised for.
 *
 * The company comes from CompanyContext on every path. The `{customer}` route
 * parameter is already company-scoped by the binding in AppServiceProvider, so a
 * customer from another company 404s before this class runs.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $company = $this->companyContext->getOrFail();

        $customers = Customer::query()
            ->where('company_id', $company->getKey())
            /*
             * is_active is an explicit filter with no default. Showing only active
             * customers would be the friendlier default for a picker, but this
             * endpoint also backs historical lookups, and a client that cannot ask
             * for an inactive customer cannot explain what an old invoice was
             * raised against.
             */
            ->when(
                $request->has('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('customer_code', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('tax_identifier', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Customers retrieved successfully.',
            data: CustomerResource::collection($customers),
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $customer = $this->customers->create($company, $request->validated());

        return ApiResponse::success(
            message: 'Customer created successfully.',
            data: new CustomerResource($customer),
            status: 201,
        );
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return ApiResponse::success(
            message: 'Customer retrieved successfully.',
            data: new CustomerResource($customer),
        );
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customers->update($customer, $request->validated());

        return ApiResponse::success(
            message: 'Customer updated successfully.',
            data: new CustomerResource($customer),
        );
    }

    public function deactivate(DeactivateCustomerRequest $request, Customer $customer): JsonResponse
    {
        /*
         * The service refuses a customer with posted invoices. That check needs a
         * query and a business rule, so it lives in the service rather than in a
         * FormRequest - the request class would be validating a rule that any other
         * caller of deactivate() would then skip.
         */
        $customer = $this->customers->deactivate($customer);

        return ApiResponse::success(
            message: 'Customer deactivated successfully.',
            data: new CustomerResource($customer),
        );
    }
}
