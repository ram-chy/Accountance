<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\PostCreditDebitNoteRequest;
use App\Http\Requests\Transactions\StoreCreditDebitNoteRequest;
use App\Http\Requests\Transactions\UpdateCreditDebitNoteRequest;
use App\Http\Resources\CreditDebitNoteResource;
use App\Models\CreditDebitNote;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Services\Accounting\Notes\CreditDebitNoteAdjustmentService;
use App\Services\Accounting\Notes\CreditDebitNotePostingService;
use App\Services\Accounting\Notes\CreditDebitNoteService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Credit and debit note endpoints.
 *
 * The draft/post split follows SalesInvoiceController and PurchaseBillController
 * exactly, and for the same structural reason: PUT calls CreditDebitNoteService,
 * which refuses a non-draft under a row lock and has no post path; POST /post calls
 * CreditDebitNotePostingService, which has no update path at all. A caller holding
 * only a reference to the draft service cannot post, so "never post from a
 * controller" is a property of the type graph rather than a convention someone has
 * to remember.
 *
 * The two adjustable-line endpoints below are the reason the adjustment arithmetic
 * is reachable at all before a note exists. A user composing a credit note needs
 * to know what is still adjustable on each source line, and the only honest answer
 * is one computed from the posted notes - so it is served per request from
 * CreditDebitNoteAdjustmentService rather than read from a stored column that could
 * be stale. They live on the invoice and the bill rather than under the notes
 * prefix because they are a property of the document being adjusted, and a note id
 * does not exist yet at the moment the question is being asked.
 */
class CreditDebitNoteController extends Controller
{
    public function __construct(
        private readonly CreditDebitNoteService $notes,
        private readonly CreditDebitNotePostingService $posting,
        private readonly CreditDebitNoteAdjustmentService $adjustments,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CreditDebitNote::class);

        $company = $this->companyContext->getOrFail();

        /*
         * Every filter is a `when` on an explicit column rather than a search over a
         * polymorphic pair, which is what having two real FKs buys: the same indexes
         * the source documents use, and no CASE on a type column in the WHERE.
         *
         * `note_type` is filtered by the client rather than inferred from which FK
         * is set, because the column is already indexed and is the authoritative
         * record - asking for SALES_CREDIT_NOTE and asking for notes with an invoice
         * are not the same question if the data were ever wrong, and the first is
         * the one the client actually meant.
         */
        $notes = CreditDebitNote::query()
            ->where('company_id', $company->getKey())
            ->when(
                $request->has('note_type'),
                fn ($query) => $query->where('note_type', $request->string('note_type')),
            )
            ->when(
                $request->has('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when(
                $request->has('sales_invoice_id'),
                fn ($query) => $query->where('sales_invoice_id', $request->integer('sales_invoice_id')),
            )
            ->when(
                $request->has('purchase_bill_id'),
                fn ($query) => $query->where('purchase_bill_id', $request->integer('purchase_bill_id')),
            )
            ->when(
                $request->has('customer_id'),
                fn ($query) => $query->where('customer_id', $request->integer('customer_id')),
            )
            ->when(
                $request->has('supplier_id'),
                fn ($query) => $query->where('supplier_id', $request->integer('supplier_id')),
            )
            ->when($request->filled('from'), fn ($query) => $query->whereDate(
                'note_date',
                '>=',
                $request->date('from')->toDateString(),
            ))
            ->when($request->filled('to'), fn ($query) => $query->whereDate(
                'note_date',
                '<=',
                $request->date('to')->toDateString(),
            ))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('note_number', 'like', $term)
                    ->orWhere('reason', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', $term)
                        ->orWhere('customer_code', 'like', $term))
                    ->orWhereHas('supplier', fn ($s) => $s
                        ->where('name', 'like', $term)
                        ->orWhere('supplier_code', 'like', $term)));
            })
            ->with(['customer', 'supplier', 'salesInvoice', 'purchaseBill'])
            ->orderByDesc('note_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Credit and debit notes retrieved successfully.',
            data: CreditDebitNoteResource::collection($notes),
        );
    }

    public function store(StoreCreditDebitNoteRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $note = $this->notes->createDraft($company, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Credit and debit note created successfully.',
            data: new CreditDebitNoteResource($this->withRelations($note)),
            status: 201,
        );
    }

    public function show(CreditDebitNote $creditDebitNote): JsonResponse
    {
        $this->authorize('view', $creditDebitNote);

        return ApiResponse::success(
            message: 'Credit and debit note retrieved successfully.',
            data: new CreditDebitNoteResource($this->withRelations($creditDebitNote)),
        );
    }

    public function update(UpdateCreditDebitNoteRequest $request, CreditDebitNote $creditDebitNote): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $updated = $this->notes->updateDraft($creditDebitNote, $company, $request->validated());

        return ApiResponse::success(
            message: 'Credit and debit note updated successfully.',
            data: new CreditDebitNoteResource($this->withRelations($updated)),
        );
    }

    public function destroy(CreditDebitNote $creditDebitNote): JsonResponse
    {
        $this->authorize('delete', $creditDebitNote);

        $this->notes->deleteDraft($creditDebitNote);

        return ApiResponse::success(message: 'Credit and debit note deleted successfully.');
    }

    /**
     * Post a draft note into the accounting record.
     *
     * The early exit below is not the guard. The posting service re-checks under its
     * row lock and throws ConflictException, which renders as the same 409 - so a
     * double-submitting client and two racing clients both get one answer, rather
     * than a 409 or a 422 depending on timing.
     */
    public function post(PostCreditDebitNoteRequest $request, CreditDebitNote $creditDebitNote): JsonResponse
    {
        if ($creditDebitNote->status->isPosted()) {
            return ApiResponse::error(
                message: 'This note is already posted and cannot be posted again.',
                status: 409,
            );
        }

        $posted = $this->posting->post($creditDebitNote, $request->user());

        return ApiResponse::success(
            message: 'Credit and debit note posted successfully.',
            data: new CreditDebitNoteResource($this->withRelations($posted)),
        );
    }

    /**
     * What is still adjustable on each line of a sales invoice.
     *
     * Served from the invoice because that is what the client is looking at when it
     * asks, and the answer is per source line - there is no note to hang it off
     * yet. The whole-document figures come with it, so a form can show both what
     * the invoice is worth and what is left of it in one response.
     *
     * The permission checked is the SOURCE DOCUMENT's view, not a note permission.
     * These figures are a property of the invoice, and SalesInvoicePolicy is the
     * policy that already answers "may this user see this invoice". A user who
     * cannot read the invoice cannot learn what is left to adjust on it, and a user
     * who can has nothing extra to be given by this endpoint - so the note's own
     * view permission is deliberately not consulted, and granting
     * `credit_debit_note.view` alone does not open a window onto every invoice in
     * the company.
     */
    public function adjustableInvoiceLines(SalesInvoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success(
            message: 'Adjustable invoice lines retrieved successfully.',
            data: [
                'sales_invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'grand_total' => $invoice->grand_total,

                /*
                 * Derivable from grand_total and net_adjustment, but stated on the
                 * response anyway: this is the number the limit is enforced against
                 * and a client should not have to re-derive it - or risk re-deriving
                 * it with the opposite sign to the service.
                 */
                'net_adjustment' => $this->adjustments->netAdjustmentForInvoice($invoice->getKey())->toDatabase(),
                'remaining_adjustable_amount' => $this->adjustments->remainingForInvoice($invoice)->toDatabase(),

                'lines' => $this->adjustments->adjustableLinesForInvoice($invoice),
            ],
        );
    }

    /**
     * The same figures for a purchase bill.
     */
    public function adjustableBillLines(PurchaseBill $bill): JsonResponse
    {
        $this->authorize('view', $bill);

        return ApiResponse::success(
            message: 'Adjustable bill lines retrieved successfully.',
            data: [
                'purchase_bill_id' => $bill->getKey(),
                'bill_number' => $bill->bill_number,
                'grand_total' => $bill->grand_total,
                'net_adjustment' => $this->adjustments->netAdjustmentForBill($bill->getKey())->toDatabase(),
                'remaining_adjustable_amount' => $this->adjustments->remainingForBill($bill)->toDatabase(),
                'lines' => $this->adjustments->adjustableLinesForBill($bill),
            ],
        );
    }

    /**
     * The relations every note response needs.
     *
     * `lines.note` is eager-loaded and is not redundant with the note already in
     * hand. A nested resource is constructed per line and is handed the LINE, not
     * the note it belongs to, so CreditDebitNoteLineResource reads the note's type
     * off the line's own relation. Without this the type would be resolved by a
     * lazy load per line - correct, but one query for every line of every note in
     * a list of notes, which is the shape of a slow page that nobody notices until
     * a customer has a thousand invoices.
     */
    private function withRelations(CreditDebitNote $note): CreditDebitNote
    {
        return $note->load([
            'lines.account',
            'lines.note',
            'customer',
            'supplier',
            'salesInvoice',
            'purchaseBill',
        ]);
    }
}
