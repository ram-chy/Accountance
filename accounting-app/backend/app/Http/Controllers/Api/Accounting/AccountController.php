<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\Accounting\AccountService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chart of Accounts endpoints.
 *
 * Every action reads the company from CompanyContext, never from the request.
 * There is no company_id parameter on any method here, so the class of bug where
 * a controller trusts a body-supplied id cannot occur here by construction.
 *
 * Route model binding is scoped to the active company in AppServiceProvider, so
 * an id belonging to another company 404s before this controller runs.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $company = $this->companyContext->getOrFail();

        /*
         * `is_active` is an explicit query parameter rather than a default,
         * because the correct default for a *chart of accounts listing* is to
         * show everything. Historical reports need deactivated accounts, and a
         * client that cannot ask for them cannot render a statement correctly.
         */
        $accounts = Account::query()
            ->where('company_id', $company->getKey())
            ->when(
                $request->has('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->when($request->filled('account_type'), fn ($query) => $query->where(
                'account_type',
                $request->string('account_type'),
            ))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term));
            })
            /*
             * withExists() adds has_journal_history in the same query instead of
             * the resource calling hasJournalHistory() per row, which would be
             * one extra query per account in the response.
             */
            ->withExists('journalLines')
            ->orderBy('code')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        // ApiResponse wraps only data; the paginator's own meta travels inside
        // the resource collection's `meta` key, which is Laravel's standard
        // ResourceCollection shape. Matches UserController's existing pattern.
        return ApiResponse::success(
            message: 'Accounts retrieved successfully.',
            data: AccountResource::collection($accounts),
        );
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $account = $this->accounts->create($company, $request->validated());

        return ApiResponse::success(
            message: 'Account created successfully.',
            data: new AccountResource($account),
            status: 201,
        );
    }

    public function show(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $account->loadCount('journalLines');

        return ApiResponse::success(
            message: 'Account retrieved successfully.',
            data: new AccountResource($account),
        );
    }

    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $updated = $this->accounts->update($account, $request->validated());

        return ApiResponse::success(
            message: 'Account updated successfully.',
            data: new AccountResource($updated),
        );
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        // AccountService refuses when the account has any journal history, so the
        // common case is a 422 with an explanation rather than a silent delete.
        $this->accounts->delete($account);

        return ApiResponse::success(message: 'Account deleted successfully.');
    }

    public function activate(Account $account): JsonResponse
    {
        $this->authorize('activate', $account);

        $activated = $this->accounts->activate($account);

        return ApiResponse::success(
            message: 'Account activated successfully.',
            data: new AccountResource($activated),
        );
    }

    public function deactivate(Account $account): JsonResponse
    {
        $this->authorize('deactivate', $account);

        /*
         * A system account that a future module owns must not be deactivated by
         * hand. The check lives here rather than in the service because it is a
         * policy question about who owns the account, not an accounting rule.
         */
        if ($account->is_system) {
            return ApiResponse::error(
                message: 'This is a system account and cannot be deactivated.',
                status: 422,
            );
        }

        $deactivated = $this->accounts->deactivate($account);

        return ApiResponse::success(
            message: 'Account deactivated successfully.',
            data: new AccountResource($deactivated),
        );
    }

    /**
     * Account types with their normal balances.
     *
     * Exposed so a client building a chart-of-accounts form does not hard-code
     * the Asset->Debit rule. The spec is explicit that the normal-balance rule
     * must be central, and a client-side copy of it is a second one.
     */
    public function types(): JsonResponse
    {
        /*
         * Reference data, not accounting history, but it is still part of the
         * chart of accounts surface and leaks the structure of every account type
         * to anyone who can reach it. It therefore takes accounts.view - the
         * same gate as the listing - rather than being left open on the argument
         * that the payload is uninteresting.
         */
        $this->authorize('viewAny', Account::class);

        return ApiResponse::success(
            message: 'Account types retrieved successfully.',
            data: collect(AccountType::cases())
                ->map(fn ($type) => [
                    'value' => $type->value,
                    'normal_balance' => $type->normalBalance()->value,
                ])
                ->all(),
        );
    }
}
