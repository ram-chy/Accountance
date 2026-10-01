<?php

namespace App\Http\Controllers\Api\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\PostPurchaseBillRequest;
use App\Http\Requests\Transactions\StorePurchaseBillRequest;
use App\Http\Requests\Transactions\UpdatePurchaseBillRequest;
use App\Http\Resources\PurchaseBillResource;
use App\Models\PurchaseBill;
use App\Services\Accounting\SettlementService;
use App\Services\CompanyContext;
use App\Services\Purchasing\PurchaseBillPostingService;
use App\Services\Purchasing\PurchaseBillService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purchase bill endpoints.
 *
 * The counterpart of SalesInvoiceController, and the same structural split: PUT
 * calls PurchaseBillService::updateDraft, which refuses a non-draft under a row
 * lock, and POST /post calls PurchaseBillPostingService, which has no update path
 * at all. A caller holding only the draft service cannot post.
 */
class PurchaseBillController extends Controller
{
    public function __construct(
        private readonly PurchaseBillService $bills,
        private readonly PurchaseBillPostingService $posting,
        private readonly SettlementService $settlement,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseBill::class);

        $company = $this->companyContext->getOrFail();

        $bills = PurchaseBill::query()
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
                'bill_date',
                '>=',
                $request->date('from')->toDateString(),
            ))
            ->when($request->filled('to'), fn ($query) => $query->whereDate(
                'bill_date',
                '<=',
                $request->date('to')->toDateString(),
            ))
            ->when($request->boolean('outstanding'), fn ($query) => $query->withOutstandingBalance())
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('bill_number', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhereHas('supplier', fn ($s) => $s
                        ->where('name', 'like', $term)
                        ->orWhere('supplier_code', 'like', $term)));
            })
            ->with(['supplier'])
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        // One aggregate for the whole page, not one per row. See the note in
        // SalesInvoiceController::index() on why the page is the right scope.
        $this->settlement->attachFiguresForBills($bills->getCollection());

        return ApiResponse::success(
            message: 'Purchase bills retrieved successfully.',
            data: PurchaseBillResource::collection($bills),
        );
    }

    public function store(StorePurchaseBillRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $bill = $this->bills->createDraft($company, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Purchase bill created successfully.',
            data: new PurchaseBillResource($bill->load(['lines.expenseAccount', 'supplier'])),
            status: 201,
        );
    }

    public function show(PurchaseBill $bill): JsonResponse
    {
        $this->authorize('view', $bill);

        $bill->load(['lines.expenseAccount', 'supplier']);

        $this->settlement->attachFiguresForBills([$bill]);

        return ApiResponse::success(
            message: 'Purchase bill retrieved successfully.',
            data: new PurchaseBillResource($bill),
        );
    }

    public function update(UpdatePurchaseBillRequest $request, PurchaseBill $bill): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $updated = $this->bills->updateDraft($bill, $company, $request->validated());

        $this->settlement->attachFiguresForBills([$updated]);

        return ApiResponse::success(
            message: 'Purchase bill updated successfully.',
            data: new PurchaseBillResource($updated->load(['lines.expenseAccount', 'supplier'])),
        );
    }

    public function destroy(PurchaseBill $bill): JsonResponse
    {
        $this->authorize('delete', $bill);

        $this->bills->deleteDraft($bill);

        return ApiResponse::success(message: 'Purchase bill deleted successfully.');
    }

    /**
     * Post a draft bill into the accounting record.
     *
     * The early exit is a courtesy, not the guard: the service re-checks under its
     * row lock and throws ConflictException, which renders as the same 409.
     */
    public function post(PostPurchaseBillRequest $request, PurchaseBill $bill): JsonResponse
    {
        if ($bill->status->isPosted()) {
            return ApiResponse::error(
                message: 'This bill is already posted. '
                    .'Record a debit note instead of posting it again.',
                status: 409,
            );
        }

        $posted = $this->posting->post($bill, $request->user());

        $posted->load(['lines.expenseAccount', 'supplier']);

        $this->settlement->attachFiguresForBills([$posted]);

        return ApiResponse::success(
            message: 'Purchase bill posted successfully.',
            data: new PurchaseBillResource($posted),
        );
    }
}
