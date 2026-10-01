<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Enums\CashBankTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\CashBankTransactionFilterRequest;
use App\Http\Requests\Transactions\PostCashBankTransactionRequest;
use App\Http\Requests\Transactions\StoreCashBankTransactionRequest;
use App\Http\Requests\Transactions\UpdateCashBankTransactionRequest;
use App\Http\Resources\CashBankTransactionResource;
use App\Models\CashBankTransaction;
use App\Services\Accounting\CashBank\CashBankPostingService;
use App\Services\Accounting\CashBank\CashBankTransactionService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Cash and bank transactions.
 *
 * Three create endpoints rather than one endpoint with a `type` field, and the
 * reason is not REST purity - it is that the type decides the accounting. A
 * deposit requires a cash/bank destination and an arbitrary offset source; a
 * withdrawal reverses that; a transfer requires both sides to be cash/bank. If
 * the type arrived in the payload, then a payload could say TRANSFER while
 * naming an expense account, and the service would be validating a rule against a
 * value the client chose rather than a route it called. Making the type a
 * property of the route removes that possibility instead of validating for it.
 *
 * Everything else follows the Phase 5 document lifecycle exactly:
 *
 *   - POST   creates a DRAFT. No journal, no ledger effect, invisible to reports.
 *   - PUT    edits a draft. Has no path to posting.
 *   - DELETE removes a draft. Has no path to posting.
 *   - POST /post posts. Has no path to editing.
 *
 * The split is structural rather than a status check inside a shared method: the
 * PUT route calls a service with no posting code path and the /post route calls a
 * different service that cannot edit. A posted transaction is therefore immutable
 * through every route on this controller, and the only way to change the record
 * after posting is to record a new, reversing transaction.
 *
 * The controller computes nothing financial. It reads the type from the route,
 * hands the validated payload to a service, and returns ApiResponse - the debit
 * and credit sides of the journal are decided by CashBankPostingService, and the
 * balance is reported by Phase 6.
 */
class CashBankTransactionController extends Controller
{
    public function __construct(
        private readonly CashBankTransactionService $transactions,
        private readonly CashBankPostingService $posting,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List cash/bank transactions for the active company.
     *
     * Filters are declared in CashBankTransactionFilterRequest rather than
     * inferred here, and every date filter addresses transaction_date. The
     * account filter matches either side, since "everything that touched this
     * account" is the question a user actually has.
     */
    public function index(CashBankTransactionFilterRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CashBankTransaction::class);

        $transactions = CashBankTransaction::query()
            ->where('company_id', $this->companyContext->getOrFail()->getKey())
            ->when(
                $request->filled('transaction_type'),
                fn ($query) => $query->where('transaction_type', $request->string('transaction_type')),
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when($request->filled('account_id'), fn ($query) => $query->where(
                fn ($q) => $q->where('source_account_id', $request->integer('account_id'))
                    ->orWhere('destination_account_id', $request->integer('account_id'))
            ))
            ->when($request->filled('from'), fn ($query) => $query->whereDate(
                'transaction_date',
                '>=',
                $request->date('from')->toDateString(),
            ))
            ->when($request->filled('to'), fn ($query) => $query->whereDate(
                'transaction_date',
                '<=',
                $request->date('to')->toDateString(),
            ))
            ->when($request->filled('reference'), fn ($query) => $query->where(
                'reference',
                'like',
                '%'.$request->string('reference')->trim().'%',
            ))
            /*
             * Eager-loaded rather than lazy-loaded. A list of transfers with no
             * accounts attached would render "Account #12 -> Account #14" and
             * force the client into two follow-up requests per row; and the source
             * of the N+1 is the controller choosing what to show, not the model.
             */
            ->with(['sourceAccount', 'destinationAccount'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Cash and bank transactions retrieved successfully.',
            data: CashBankTransactionResource::collection($transactions),
        );
    }

    /**
     * Record a deposit: money entering a cash/bank account.
     */
    public function storeDeposit(StoreCashBankTransactionRequest $request): JsonResponse
    {
        return $this->store($request, CashBankTransactionType::Deposit);
    }

    /**
     * Record a withdrawal: money leaving a cash/bank account.
     */
    public function storeWithdrawal(StoreCashBankTransactionRequest $request): JsonResponse
    {
        return $this->store($request, CashBankTransactionType::Withdrawal);
    }

    /**
     * Record a transfer between two cash/bank accounts.
     */
    public function storeTransfer(StoreCashBankTransactionRequest $request): JsonResponse
    {
        return $this->store($request, CashBankTransactionType::Transfer);
    }

    public function show(CashBankTransaction $transaction): JsonResponse
    {
        $this->authorize('view', $transaction);

        return ApiResponse::success(
            message: 'Cash and bank transaction retrieved successfully.',
            data: new CashBankTransactionResource(
                $transaction->load(['sourceAccount', 'destinationAccount', 'journal.lines']),
            ),
        );
    }

    /**
     * Edit a draft.
     *
     * The transaction type is read from the stored row, not from the request,
     * because it cannot change - and the service needs it to know which side of
     * the account pair has to be cash/bank once the incoming fields are merged
     * with the stored ones.
     */
    public function update(
        UpdateCashBankTransactionRequest $request,
        CashBankTransaction $transaction
    ): JsonResponse {
        $updated = $this->transactions->updateDraft(
            $transaction,
            $this->companyContext->getOrFail(),
            $transaction->transaction_type,
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Cash and bank transaction updated successfully.',
            data: new CashBankTransactionResource(
                $updated->load(['sourceAccount', 'destinationAccount']),
            ),
        );
    }

    /**
     * Delete a draft. A posted transaction is refused by the service, which is
     * why there is no status check in this controller.
     */
    public function destroy(CashBankTransaction $transaction): JsonResponse
    {
        $this->authorize('delete', $transaction);

        $this->transactions->deleteDraft($transaction);

        return ApiResponse::success(message: 'Cash and bank transaction deleted successfully.');
    }

    /**
     * Post a draft into the accounting record.
     *
     * The early exit is a courtesy; the service re-reads the status under a row
     * lock and throws the equivalent 409. The check here exists so the common
     * mistake gets a clear message, not so that correctness depends on it -
     * between this test and the service's lock, a second request can arrive.
     */
    public function post(
        PostCashBankTransactionRequest $request,
        CashBankTransaction $transaction
    ): JsonResponse {
        if ($transaction->status->isPosted()) {
            return ApiResponse::error(
                message: 'This cash/bank transaction is already posted. '
                    .'Record a reversing transaction instead of posting it again.',
                status: 409,
            );
        }

        $posted = $this->posting->post($transaction, $request->user());

        return ApiResponse::success(
            message: 'Cash and bank transaction posted successfully.',
            data: new CashBankTransactionResource(
                $posted->load(['sourceAccount', 'destinationAccount', 'journal.lines']),
            ),
        );
    }

    /**
     * Shared create path for the three movement types.
     *
     * Thin by design: the type comes from the caller - which is the route - and
     * everything else is the service's.
     */
    private function store(
        StoreCashBankTransactionRequest $request,
        CashBankTransactionType $type
    ): JsonResponse {
        $transaction = $this->transactions->createDraft(
            $this->companyContext->getOrFail(),
            $request->user(),
            $type,
            $request->validated(),
        );

        return ApiResponse::success(
            message: sprintf('%s cash/bank transaction created successfully.', ucfirst(strtolower($type->value))),
            data: new CashBankTransactionResource(
                $transaction->load(['sourceAccount', 'destinationAccount']),
            ),
            status: 201,
        );
    }
}
