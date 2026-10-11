<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreJournalRequest;
use App\Http\Requests\Accounting\UpdateJournalRequest;
use App\Http\Resources\JournalResource;
use App\Models\Journal;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal endpoints.
 *
 * Split by lifecycle: the store/update/destroy methods here own DRAFT work
 * through JournalService, and post() delegates to JournalPostingService. That
 * boundary is the reason posted-journal immutability is a fact rather than a
 * check - the controller that could edit a journal cannot post it.
 *
 * The company comes from CompanyContext on every path. No method accepts a
 * company id, and route binding is scoped to the active company, so a journal
 * belonging to another company 404s before any code here runs.
 */
class JournalController extends Controller
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Journal::class);

        $company = $this->companyContext->getOrFail();

        $journals = Journal::query()
            ->where('company_id', $company->getKey())
            /*
             * Status is filterable but has no default. Showing only drafts or
             * only posted would each be wrong half the time, and a client that
             * wants one can ask for it explicitly.
             */
            ->when(
                $request->has('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when($request->filled('from'), fn ($query) => $query->whereDate(
                'journal_date',
                '>=',
                $request->date('from')->toDateString(),
            ))
            ->when($request->filled('to'), fn ($query) => $query->whereDate(
                'journal_date',
                '<=',
                $request->date('to')->toDateString(),
            ))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('description', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('journal_number', 'like', $term));
            })
            ->with('lines.account', 'lines.currency', 'lines.journalLineDimensions')
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Journals retrieved successfully.',
            data: JournalResource::collection($journals),
        );
    }

    public function store(StoreJournalRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $journal = $this->journals->createDraft(
            $company,
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            message: 'Journal created successfully.',
            data: new JournalResource($journal->load('lines.account', 'lines.currency', 'lines.journalLineDimensions')),
            status: 201,
        );
    }

    public function show(Journal $journal): JsonResponse
    {
        $this->authorize('view', $journal);

        return ApiResponse::success(
            message: 'Journal retrieved successfully.',
            data: new JournalResource($journal->load('lines.account', 'lines.currency', 'lines.journalLineDimensions')),
        );
    }

    public function update(UpdateJournalRequest $request, Journal $journal): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $updated = $this->journals->updateDraft($journal, $company, $request->validated());

        return ApiResponse::success(
            message: 'Journal updated successfully.',
            data: new JournalResource($updated->load('lines.account', 'lines.currency', 'lines.journalLineDimensions')),
        );
    }

    public function destroy(Journal $journal): JsonResponse
    {
        $this->authorize('delete', $journal);

        $this->journals->deleteDraft($journal);

        return ApiResponse::success(message: 'Journal deleted successfully.');
    }

    /**
     * Post a draft journal into the accounting record.
     *
     * Validation failures come back as 422 with per-field messages from the
     * posting service. A second POST of an already-posted journal is a 409, not
     * a silent success - see the decision recorded in the Phase 4 report.
     */
    public function post(Request $request, Journal $journal): JsonResponse
    {
        $this->authorize('post', $journal);

        /*
         * This is an early exit, not the guard. The service re-checks under its
         * row lock and throws ConflictException, which renders as the same 409 -
         * so a client that retries twice, or two clients that race, both get one
         * answer rather than a 409 or a 422 depending on timing.
         */
        if ($journal->status->isPosted()) {
            return ApiResponse::error(
                message: 'This journal is already posted. '
                    .'Record a correcting journal instead of posting again.',
                status: 409,
            );
        }

        $posted = $this->posting->post($journal, $request->user());

        return ApiResponse::success(
            message: 'Journal posted successfully.',
            data: new JournalResource($posted->load('lines.account', 'lines.currency', 'lines.journalLineDimensions')),
        );
    }
}
