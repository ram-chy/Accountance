<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Enums\CashBankKind;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\SetCashBankAccountActiveRequest;
use App\Http\Requests\Transactions\UpdateCashBankAccountRequest;
use App\Http\Resources\CashBankAccountResource;
use App\Models\Account;
use App\Services\Accounting\CashBank\CashBankAccountService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cash and bank account configuration.
 *
 * There is no `store` here. Creating an account is AccountController's job under
 * accounts.create, and a cash/bank account is an ordinary account in the chart of
 * accounts with a classification on it - not a separate kind of thing that gets
 * created through a second door. A client creates the account, then classifies it
 * through this controller, and the classification is what makes it eligible.
 *
 * Authorization is a direct permission check rather than a policy method, and
 * that is a deliberate exception to the convention used elsewhere in this
 * project. A policy is resolved per model class, and Account already has
 * AccountPolicy; a second policy for the same model could never be reached
 * through authorize(), so writing one would have been a class that looks like it
 * enforces something and in fact never runs. Checking the permission directly is
 * the honest option here.
 *
 * It is also safe, which is the property StoreAccountRequest's docblock cares
 * about: that class avoids a raw permission string because it would skip the
 * membership half of the check. Here the membership half is not skipped - the
 * `account` route binding is scoped to the active company in AppServiceProvider,
 * so the Account reaching this controller is already known to belong to the
 * caller's company or the request has 404ed.
 */
class CashBankAccountController extends Controller
{
    public function __construct(
        private readonly CashBankAccountService $cashBank,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * The cash/bank accounts available for new movements.
     *
     * Filterable by kind, because "which bank accounts do we have" and "which
     * cash accounts do we have" are different questions - a petty cash float and
     * a current account are both eligible for a movement, but they are not
     * interchangeable when someone is choosing one.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can(PermissionName::CashBankView->value),
            403,
            'You do not have permission to view cash and bank accounts.'
        );

        $kind = $request->filled('cash_bank_kind')
            ? CashBankKind::from($request->string('cash_bank_kind')->toString())
            : null;

        $accounts = $this->cashBank->listFor($this->companyContext->getOrFail(), $kind);

        return ApiResponse::success(
            message: 'Cash and bank accounts retrieved successfully.',
            data: CashBankAccountResource::collection($accounts),
        );
    }

    /**
     * Classify an account and, optionally, record its bank details.
     *
     * One endpoint rather than two, because the two almost always happen
     * together: classifying an account as BANK and then describing the bank it is
     * at are one user action in a form. Sending cash_bank_kind: null removes the
     * classification, which the service permits only for an account with no bank
     * details and no accounting history.
     */
    public function update(UpdateCashBankAccountRequest $request, Account $account): JsonResponse
    {
        $company = $this->companyContext->getOrFail();
        $data = $request->validated();

        if (array_key_exists('cash_bank_kind', $data)) {
            $requested = $data['cash_bank_kind'] === null
                ? null
                : CashBankKind::from($data['cash_bank_kind']);

            $account = $requested === null
                ? $this->cashBank->clear($company, $account)
                : $this->cashBank->markAs($company, $account, $requested);
        }

        if (array_key_exists('bank_account', $data) && $data['bank_account'] !== null) {
            $this->cashBank->saveBankDetails($company, $account, $data['bank_account']);
        }

        return ApiResponse::success(
            message: 'Cash and bank account updated successfully.',
            data: new CashBankAccountResource($account->refresh()->load('bankAccount')),
        );
    }

    /**
     * Remove an account's bank details.
     *
     * The classification is left alone. Removing the details of a bank that has
     * been closed is a fact about the bank; turning the account back into an
     * ordinary asset is a separate decision, and one the service will refuse once
     * the account has accounting history.
     */
    public function destroyBankDetails(Account $account): JsonResponse
    {
        abort_unless(
            request()->user()->can(PermissionName::CashBankUpdate->value),
            403,
            'You do not have permission to change cash and bank accounts.'
        );

        $this->cashBank->deleteBankDetails($this->companyContext->getOrFail(), $account);

        return ApiResponse::success(message: 'Bank details removed successfully.');
    }

    /**
     * Make an account's bank details usable again.
     */
    public function activate(SetCashBankAccountActiveRequest $request, Account $account): JsonResponse
    {
        $this->cashBank->setBankDetailsActive(
            $this->companyContext->getOrFail(),
            $account,
            active: true
        );

        return ApiResponse::success(
            message: 'Bank details activated successfully.',
            data: new CashBankAccountResource($account->refresh()->load('bankAccount')),
        );
    }

    /**
     * Stop an account's bank details being selectable for new movements.
     *
     * Deliberately not accounts.is_active. Closing a bank account is not the same
     * as retiring an account from the chart, and a user should not have to
     * deactivate an account in one system to stop using it in another.
     */
    public function deactivate(SetCashBankAccountActiveRequest $request, Account $account): JsonResponse
    {
        $this->cashBank->setBankDetailsActive(
            $this->companyContext->getOrFail(),
            $account,
            active: false
        );

        return ApiResponse::success(
            message: 'Bank details deactivated successfully.',
            data: new CashBankAccountResource($account->refresh()->load('bankAccount')),
        );
    }
}
