<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Reconciliation\AddBankReconciliationItemRequest;
use App\Http\Requests\Accounting\Reconciliation\BankReconciliationFilterRequest;
use App\Http\Requests\Accounting\Reconciliation\CompleteBankReconciliationRequest;
use App\Http\Requests\Accounting\Reconciliation\CreateBankReconciliationRequest;
use App\Http\Requests\Accounting\Reconciliation\ReopenBankReconciliationRequest;
use App\Http\Requests\Accounting\Reconciliation\UpdateBankReconciliationRequest;
use App\Http\Resources\BankReconciliationMovementResource;
use App\Http\Resources\BankReconciliationResource;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Services\Accounting\Reconciliation\BankReconciliationCompletionService;
use App\Services\Accounting\Reconciliation\BankReconciliationMovementService;
use App\Services\Accounting\Reconciliation\BankReconciliationReopenService;
use App\Services\Accounting\Reconciliation\BankReconciliationService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $reconciliations,
        private readonly BankReconciliationMovementService $movements,
        private readonly BankReconciliationCompletionService $completion,
        private readonly BankReconciliationReopenService $reopenService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(BankReconciliationFilterRequest $request): JsonResponse
    {
        $this->authorize('viewAny', BankReconciliation::class);
        $company = $this->companyContext->getOrFail();

        $query = BankReconciliation::query()
            ->where('company_id', $company->getKey())
            ->when($request->filled('bank_account_id'), fn ($q) => $q->where('bank_account_id', $request->integer('bank_account_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('from_date', '>=', $request->date('from_date')->toDateString()))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('to_date', '<=', $request->date('to_date')->toDateString()))
            ->with(['bankAccount', 'account'])
            ->orderByDesc('id');

        $reconciliations = $query->paginate($request->integer('per_page', 25))->withQueryString();

        return ApiResponse::success(
            message: 'Bank reconciliations retrieved successfully.',
            data: BankReconciliationResource::collection($reconciliations),
        );
    }

    public function store(CreateBankReconciliationRequest $request): JsonResponse
    {
        $this->authorize('create', BankReconciliation::class);
        $company = $this->companyContext->getOrFail();
        $actor = $request->user();

        $reconciliation = $this->reconciliations->create($company, $actor, $request->validated());

        return ApiResponse::success(
            message: 'Bank reconciliation created successfully.',
            data: BankReconciliationResource::make($reconciliation->refresh()),
            status: 201,
        );
    }

    public function show(BankReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('view', $reconciliation);

        return ApiResponse::success(
            message: 'Bank reconciliation retrieved successfully.',
            data: BankReconciliationResource::make($reconciliation->load(['bankAccount', 'account'])),
        );
    }

    public function update(UpdateBankReconciliationRequest $request, BankReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('update', $reconciliation);
        $reconciliation = $this->reconciliations->update($reconciliation, $request->validated());

        return ApiResponse::success(
            message: 'Bank reconciliation updated successfully.',
            data: BankReconciliationResource::make($reconciliation->refresh()),
        );
    }

    public function destroy(BankReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('delete', $reconciliation);
        $this->reconciliations->deleteDraft($reconciliation);

        return ApiResponse::success(
            message: 'Bank reconciliation deleted successfully.',
            data: null,
        );
    }

    public function movements(BankReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('view', $reconciliation);
        $movements = $this->movements->eligibleMovements($reconciliation);

        return ApiResponse::success(
            message: 'Bank reconciliation movements retrieved successfully.',
            data: BankReconciliationMovementResource::collection($movements),
        );
    }

    public function addItem(AddBankReconciliationItemRequest $request, BankReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('update', $reconciliation);
        $item = $this->movements->addItem(
            $reconciliation,
            $request->integer('journal_line_id'),
            $request->string('notes')->toString() ?: null
        );

        return ApiResponse::success(
            message: 'Movement cleared successfully.',
            data: ['id' => $item->getKey()],
        );
    }

    public function removeItem(BankReconciliation $reconciliation, BankReconciliationItem $item): JsonResponse
    {
        $this->authorize('update', $reconciliation);
        $this->movements->removeItem($reconciliation, $item);

        return ApiResponse::success(
            message: 'Cleared movement removed successfully.',
            data: null,
        );
    }

    public function complete(CompleteBankReconciliationRequest $request, BankReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('complete', $reconciliation);
        $reconciliation = $this->completion->complete($reconciliation);

        return ApiResponse::success(
            message: 'Bank reconciliation completed successfully.',
            data: BankReconciliationResource::make($reconciliation->refresh()),
        );
    }

    public function reopen(ReopenBankReconciliationRequest $request, BankReconciliation $reconciliation): JsonResponse
    {
        $this->authorize('reopen', $reconciliation);
        $reconciliation = $this->reopenService->reopen($reconciliation);

        return ApiResponse::success(
            message: 'Bank reconciliation reopened successfully.',
            data: BankReconciliationResource::make($reconciliation->refresh()),
        );
    }
}
