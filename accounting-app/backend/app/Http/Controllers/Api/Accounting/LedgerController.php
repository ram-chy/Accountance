<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\LedgerService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ledger read endpoints: account balances and the trial balance.
 *
 * Read-only by construction - there is no write method in this controller, and
 * no service it calls can write a ledger row. Balances are always derived from
 * posted journal lines, so this class cannot introduce a second source of truth.
 */
class LedgerController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * One account's balance, plus an optional running-balance statement.
     */
    public function accountBalance(Request $request, Account $account): JsonResponse
    {
        /*
         * Gated on accounting.ledger.view rather than on the account's own
         * `view` policy. accounts.view answers "may this user see the list of
         * accounts"; this endpoint answers "may this user see what those
         * accounts are worth". PermissionName documents the split, and honouring
         * it here is the only place the ledger permission is enforced - if it
         * were left to fall through to accounts.view, a user granted the chart of
         * accounts would silently gain the company's financial position too.
         */
        $this->authorize(PermissionName::LedgerView->value);

        $from = $request->filled('from') ? $request->date('from') : null;
        $to = $request->filled('to') ? $request->date('to') : null;

        $totals = $this->ledger->totalsFor($account, $from, $to);
        $balance = $this->ledger->balanceFor($account, $from, $to);

        $payload = [
            'account_id' => $account->getKey(),
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type->value,
            'normal_balance' => $account->normalBalance()->value,
            'is_active' => $account->is_active,
            'total_debit' => (string) $totals['debit'],
            'total_credit' => (string) $totals['credit'],
            /*
             * Signed, on the account's normal side. A negative value means the
             * account is over-balanced the other way, which is reported as-is
             * rather than hidden by taking the magnitude.
             */
            'balance' => (string) $balance,
        ];

        if ($request->boolean('include_statement')) {
            $payload['statement'] = $this->ledger->accountStatement($account, $from, $to);
        }

        return ApiResponse::success(
            message: 'Account balance retrieved successfully.',
            data: $payload,
        );
    }

    /**
     * The trial balance.
     *
     * Draft journals are excluded by LedgerService's query, and another
     * company's journals are excluded by the company filter on the joined
     * journals table. Both properties are verified by the trial balance tests
     * rather than assumed.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        // Same reasoning as accountBalance(): the trial balance is a ledger read,
        // so it takes the ledger permission and not journals.view.
        $this->authorize(PermissionName::LedgerView->value);

        $from = $request->filled('from') ? $request->date('from') : null;
        $to = $request->filled('to') ? $request->date('to') : null;

        $result = $this->ledger->trialBalance($company, $from, $to);

        return ApiResponse::success(
            message: 'Trial balance retrieved successfully.',
            data: [
                'accounts' => $result['accounts'],
                'total_debit' => (string) $result['total_debit'],
                'total_credit' => (string) $result['total_credit'],
                /*
                 * The footing check. If this ever reads false, posted journal
                 * lines do not sum to zero and the ledger is corrupt - it is
                 * surfaced in the response rather than swallowed so the fault is
                 * visible instead of being reported as a normal trial balance.
                 */
                'is_balanced' => $result['balanced'],
                'difference' => (string) $result['total_debit']->minus($result['total_credit']),
            ],
        );
    }
}
