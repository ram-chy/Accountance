<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\PostCustomerReceiptRequest;
use App\Http\Requests\Transactions\StoreCustomerReceiptRequest;
use App\Http\Requests\Transactions\UpdateCustomerReceiptRequest;
use App\Http\Resources\CustomerReceiptResource;
use App\Models\CustomerReceipt;
use App\Services\CompanyContext;
use App\Services\Sales\CustomerReceiptPostingService;
use App\Services\Sales\CustomerReceiptService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer receipt endpoints.
 *
 * The same lifecycle split as invoices, with one difference that is worth being
 * explicit about: a receipt's PUT can change its allocations, and that is safe
 * only because the allocations of a *draft* receipt have not moved money. Once
 * posted, the allocations are the reason the journal exists, and
 * CustomerReceiptService::updateDraft refuses the edit under a row lock rather
 * than trusting the caller to have checked.
 */
class CustomerReceiptController extends Controller
{
    public function __construct(
        private readonly CustomerReceiptService $receipts,
        private readonly CustomerReceiptPostingService $posting,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerReceipt::class);

        $company = $this->companyContext->getOrFail();

        $receipts = CustomerReceipt::query()
            ->where('company_id', $company->getKey())
            ->when(
                $request->has('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when(
                $request->has('customer_id'),
                fn ($query) => $query->where('customer_id', $request->integer('customer_id')),
            )
            ->when($request->filled('from'), fn ($query) => $query->whereDate(
                'receipt_date',
                '>=',
                $request->date('from')->toDateString(),
            ))
            ->when($request->filled('to'), fn ($query) => $query->whereDate(
                'receipt_date',
                '<=',
                $request->date('to')->toDateString(),
            ))
            ->when($request->filled('reference'), fn ($query) => $query->where(
                'reference',
                'like',
                '%'.$request->string('reference')->trim().'%',
            ))
            ->with(['customer', 'allocations.invoice'])
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Customer receipts retrieved successfully.',
            data: CustomerReceiptResource::collection($receipts),
        );
    }

    public function store(StoreCustomerReceiptRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $receipt = $this->receipts->createDraft($company, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Customer receipt created successfully.',
            data: new CustomerReceiptResource($receipt->load(['customer', 'allocations.invoice'])),
            status: 201,
        );
    }

    public function show(CustomerReceipt $receipt): JsonResponse
    {
        $this->authorize('view', $receipt);

        return ApiResponse::success(
            message: 'Customer receipt retrieved successfully.',
            data: new CustomerReceiptResource(
                $receipt->load(['customer', 'allocations.invoice']),
            ),
        );
    }

    public function update(UpdateCustomerReceiptRequest $request, CustomerReceipt $receipt): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $updated = $this->receipts->updateDraft($receipt, $company, $request->validated());

        return ApiResponse::success(
            message: 'Customer receipt updated successfully.',
            data: new CustomerReceiptResource($updated->load(['customer', 'allocations.invoice'])),
        );
    }

    public function destroy(CustomerReceipt $receipt): JsonResponse
    {
        $this->authorize('delete', $receipt);

        $this->receipts->deleteDraft($receipt);

        return ApiResponse::success(message: 'Customer receipt deleted successfully.');
    }

    /**
     * Post a draft receipt into the accounting record.
     *
     * The early exit is a courtesy; the service re-checks under its lock and
     * throws the equivalent 409.
     */
    public function post(PostCustomerReceiptRequest $request, CustomerReceipt $receipt): JsonResponse
    {
        if ($receipt->status->isPosted()) {
            return ApiResponse::error(
                message: 'This receipt is already posted. '
                    .'Record a reversing receipt instead of posting it again.',
                status: 409,
            );
        }

        $posted = $this->posting->post($receipt, $request->user());

        return ApiResponse::success(
            message: 'Customer receipt posted successfully.',
            data: new CustomerReceiptResource(
                $posted->load(['customer', 'allocations.invoice', 'journal.lines']),
            ),
        );
    }
}
