<?php

namespace App\Http\Controllers\Api\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\PostSupplierPaymentRequest;
use App\Http\Requests\Transactions\StoreSupplierPaymentRequest;
use App\Http\Requests\Transactions\UpdateSupplierPaymentRequest;
use App\Http\Resources\SupplierPaymentResource;
use App\Models\SupplierPayment;
use App\Services\CompanyContext;
use App\Services\Purchasing\SupplierPaymentPostingService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supplier payment endpoints.
 *
 * The counterpart of CustomerReceiptController, with the same lifecycle split:
 * PUT can only edit a draft, and its allocations only stop being editable once
 * the journal that relies on them exists.
 */
class SupplierPaymentController extends Controller
{
    public function __construct(
        private readonly SupplierPaymentService $payments,
        private readonly SupplierPaymentPostingService $posting,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupplierPayment::class);

        $company = $this->companyContext->getOrFail();

        $payments = SupplierPayment::query()
            ->where('company_id', $company->getKey())
            ->when(
                $request->has('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when(
                $request->has('supplier_id'),
                fn ($query) => $query->where('supplier_id', $request->integer('supplier_id')),
            )
            ->when($request->filled('from'), fn ($query) => $query->whereDate(
                'payment_date',
                '>=',
                $request->date('from')->toDateString(),
            ))
            ->when($request->filled('to'), fn ($query) => $query->whereDate(
                'payment_date',
                '<=',
                $request->date('to')->toDateString(),
            ))
            ->when($request->filled('reference'), fn ($query) => $query->where(
                'reference',
                'like',
                '%'.$request->string('reference')->trim().'%',
            ))
            ->with(['supplier', 'allocations.bill'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Supplier payments retrieved successfully.',
            data: SupplierPaymentResource::collection($payments),
        );
    }

    public function store(StoreSupplierPaymentRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $payment = $this->payments->createDraft($company, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Supplier payment created successfully.',
            data: new SupplierPaymentResource($payment->load(['supplier', 'allocations.bill'])),
            status: 201,
        );
    }

    public function show(SupplierPayment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::success(
            message: 'Supplier payment retrieved successfully.',
            data: new SupplierPaymentResource(
                $payment->load(['supplier', 'allocations.bill']),
            ),
        );
    }

    public function update(UpdateSupplierPaymentRequest $request, SupplierPayment $payment): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $updated = $this->payments->updateDraft($payment, $company, $request->validated());

        return ApiResponse::success(
            message: 'Supplier payment updated successfully.',
            data: new SupplierPaymentResource($updated->load(['supplier', 'allocations.bill'])),
        );
    }

    public function destroy(SupplierPayment $payment): JsonResponse
    {
        $this->authorize('delete', $payment);

        $this->payments->deleteDraft($payment);

        return ApiResponse::success(message: 'Supplier payment deleted successfully.');
    }

    public function post(PostSupplierPaymentRequest $request, SupplierPayment $payment): JsonResponse
    {
        if ($payment->status->isPosted()) {
            return ApiResponse::error(
                message: 'This payment is already posted. '
                    .'Record a reversing payment instead of posting it again.',
                status: 409,
            );
        }

        $posted = $this->posting->post($payment, $request->user());

        return ApiResponse::success(
            message: 'Supplier payment posted successfully.',
            data: new SupplierPaymentResource(
                $posted->load(['supplier', 'allocations.bill', 'journal.lines']),
            ),
        );
    }
}
