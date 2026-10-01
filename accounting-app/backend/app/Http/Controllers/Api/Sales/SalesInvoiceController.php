<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\PostSalesInvoiceRequest;
use App\Http\Requests\Transactions\StoreSalesInvoiceRequest;
use App\Http\Requests\Transactions\UpdateSalesInvoiceRequest;
use App\Http\Resources\SalesInvoiceResource;
use App\Models\SalesInvoice;
use App\Services\Accounting\SettlementService;
use App\Services\CompanyContext;
use App\Services\Sales\SalesInvoicePostingService;
use App\Services\Sales\SalesInvoiceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sales invoice endpoints.
 *
 * The draft/post split follows JournalController. PUT can never post an invoice
 * because it calls SalesInvoiceService::updateDraft, which refuses a non-draft
 * under a row lock; POST /post can never edit one because it calls
 * SalesInvoicePostingService, which has no update path at all. The separation is
 * structural - a caller holding only a SalesInvoiceService reference cannot post.
 *
 * Two filters on index are worth explaining because they are not obvious from the
 * query:
 *
 *  - `outstanding` selects invoices with a balance, via the model's
 *    withOutstandingBalance() scope. That scope is a correlated subquery over
 *    posted receipt allocations rather than a comparison against a stored
 *    paid_total, because that column does not exist. Chaining the conditions by
 *    hand in the controller would also be wrong in a way that is easy to miss: an
 *    `orWhereDoesntHave` written here would bind to the whole preceding AND
 *    group, not to the one filter, and the query would return every invoice with
 *    no allocations regardless of the other filters. Inside a scope the grouping
 *    is explicit.
 *
 *  - `status` takes a raw value, so a client can ask for PARTIALLY_PAID and get
 *    what is stored. The stored status is maintained from allocations on every
 *    posting, so it is current; it is a cache of a sum, not a second source of
 *    truth, and the resource recomputes the figures behind it.
 */
class SalesInvoiceController extends Controller
{
    public function __construct(
        private readonly SalesInvoiceService $invoices,
        private readonly SalesInvoicePostingService $posting,
        private readonly SettlementService $settlement,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SalesInvoice::class);

        $company = $this->companyContext->getOrFail();

        $invoices = SalesInvoice::query()
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
                'invoice_date',
                '>=',
                $request->date('from')->toDateString(),
            ))
            ->when($request->filled('to'), fn ($query) => $query->whereDate(
                'invoice_date',
                '<=',
                $request->date('to')->toDateString(),
            ))
            ->when($request->boolean('outstanding'), fn ($query) => $query->withOutstandingBalance())
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('invoice_number', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', $term)
                        ->orWhere('customer_code', 'like', $term)));
            })
            ->with(['customer'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        /*
         * paid_total and balance_due for the whole page, in one aggregate.
         * Resource-level, because the pagination happens first - and the correct
         * semantics are the page's, not the filter's: reporting the outstanding
         * invoices for the entire filtered set on each row of page 2 would be a
         * different number every time the user scrolled.
         */
        $this->settlement->attachFiguresForInvoices($invoices->getCollection());

        return ApiResponse::success(
            message: 'Sales invoices retrieved successfully.',
            data: SalesInvoiceResource::collection($invoices),
        );
    }

    public function store(StoreSalesInvoiceRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $invoice = $this->invoices->createDraft($company, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Sales invoice created successfully.',
            data: new SalesInvoiceResource($invoice->load(['lines.revenueAccount', 'customer'])),
            status: 201,
        );
    }

    public function show(SalesInvoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        $invoice->load(['lines.revenueAccount', 'customer']);

        $this->settlement->attachFiguresForInvoices([$invoice]);

        return ApiResponse::success(
            message: 'Sales invoice retrieved successfully.',
            data: new SalesInvoiceResource($invoice),
        );
    }

    public function update(UpdateSalesInvoiceRequest $request, SalesInvoice $invoice): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $updated = $this->invoices->updateDraft($invoice, $company, $request->validated());

        $this->settlement->attachFiguresForInvoices([$updated]);

        return ApiResponse::success(
            message: 'Sales invoice updated successfully.',
            data: new SalesInvoiceResource($updated->load(['lines.revenueAccount', 'customer'])),
        );
    }

    public function destroy(SalesInvoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);

        $this->invoices->deleteDraft($invoice);

        return ApiResponse::success(message: 'Sales invoice deleted successfully.');
    }

    /**
     * Post a draft invoice into the accounting record.
     *
     * The early exit below is not the guard. The service re-checks under its row
     * lock and throws ConflictException, which renders as the same 409 - so a
     * double-submitting client and two racing clients both get one answer,
     * instead of a 409 or a 422 depending on timing.
     */
    public function post(PostSalesInvoiceRequest $request, SalesInvoice $invoice): JsonResponse
    {
        if ($invoice->status->isPosted()) {
            return ApiResponse::error(
                message: 'This invoice is already posted. '
                    .'Record a credit note instead of posting it again.',
                status: 409,
            );
        }

        $posted = $this->posting->post($invoice, $request->user());

        $posted->load(['lines.revenueAccount', 'customer']);

        $this->settlement->attachFiguresForInvoices([$posted]);

        return ApiResponse::success(
            message: 'Sales invoice posted successfully.',
            data: new SalesInvoiceResource($posted),
        );
    }
}
