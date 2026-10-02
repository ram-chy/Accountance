<?php

use App\Http\Controllers\Api\Accounting\AccountController;
use App\Http\Controllers\Api\Accounting\AccountingPeriodController;
use App\Http\Controllers\Api\Accounting\BankReconciliationController;
use App\Http\Controllers\Api\Accounting\CashBankAccountController;
use App\Http\Controllers\Api\Accounting\CashBankTransactionController;
use App\Http\Controllers\Api\Accounting\FinancialYearController;
use App\Http\Controllers\Api\Accounting\JournalController;
use App\Http\Controllers\Api\Accounting\LedgerController;
use App\Http\Controllers\Api\Accounting\ReportController;
use App\Http\Controllers\Api\Accounting\TaxController;
use App\Http\Controllers\Api\Accounting\TaxReportController;
use App\Http\Controllers\Api\AuthenticatedSessionController;
use App\Http\Controllers\Api\CompanyContextController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanySettingsController;
use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\NewPasswordController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PasswordResetLinkController;
use App\Http\Controllers\Api\Purchasing\PurchaseBillController;
use App\Http\Controllers\Api\Purchasing\SupplierController;
use App\Http\Controllers\Api\Purchasing\SupplierPaymentController;
use App\Http\Controllers\Api\RegisteredUserController;
use App\Http\Controllers\Api\Sales\CustomerController;
use App\Http\Controllers\Api\Sales\CustomerReceiptController;
use App\Http\Controllers\Api\Sales\SalesInvoiceController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Authentication uses a single JWT guard. Routes are split explicitly:
| public endpoints never require a token, and every other module must be
| placed inside the `auth:api` group so new business endpoints are protected
| by default.
|
*/

Route::get('/health', HealthController::class)->name('api.health');

/*
| Public authentication endpoints.
*/
Route::middleware('throttle:auth')->prefix('auth')->group(function () {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register')
        ->name('auth.register');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:forgot-password')
        ->name('auth.password.email');

    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:reset-password')
        ->name('auth.password.reset');

    /*
    | Laravel's ResetPassword notification builds its link against the
    | `password.reset` route name. This backend is headless, so instead of a
    | server-rendered form the GET route reports whether the supplied token is
    | still valid; the client then POSTs the new password to the route above.
    */
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'show'])
        ->middleware('throttle:reset-password')
        ->where('token', '[A-Za-z0-9]+')
        ->name('password.reset');

    /*
    | Laravel's VerifyEmail notification builds a temporary signed GET link
    | against the `verification.verify` route name. That link is opened from a
    | mail client, so it cannot carry a bearer token and must stay public.
    | The signature plus the id/hash pair are the authorisation.
    */
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'update'])
        ->middleware(['signed', 'throttle:verify-email'])
        ->whereNumber('id')
        ->name('verification.verify');
});

/*
| Authenticated endpoints.
|
| `auth.fresh` re-checks account status and the token's password-version claim
| on every request, so deactivating a user or changing their password takes
| effect immediately instead of waiting for the token to expire.
*/
Route::middleware(['auth:api', 'auth.fresh', 'throttle:api'])->prefix('auth')->group(function () {
    Route::get('/me', [CurrentUserController::class, 'show'])->name('auth.me');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('auth.logout');

    Route::put('/password', [PasswordController::class, 'update'])
        ->middleware('throttle:change-password')
        ->name('auth.password.change');

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'store'])
        ->middleware('throttle:verify-email')
        ->name('auth.verification.send');
});

/*
| Admin user management.
|
| The FormRequest::authorize() methods and UserPolicy enforce the permission
| server-side, so a hidden UI button is never the only protection.
*/
Route::middleware(['auth:api', 'auth.fresh', 'throttle:api'])->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('users.index');
    Route::post('/', [UserController::class, 'store'])->name('users.store');
    Route::get('/{user}', [UserController::class, 'show'])->name('users.show');
    Route::put('/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

/*
| Companies.
|
| Company-scoped routes require an authenticated user who is a member of the
| company in the URL. That membership is established in three places that all
| run before the controller:
|
|   1. CompanyPolicy            - membership AND permission, in that order
|   2. FormRequest::authorize() - same policy, so validation cannot be reached
|                                 without authorization
|   3. ResolveCompanyContext     - the company.context middleware, for routes
|                                 that act on the request's active company
|
| A member of Company A therefore cannot reach Company B by editing the id.
*/
Route::middleware(['auth:api', 'auth.fresh', 'throttle:api'])
    ->prefix('companies')
    ->group(function () {
        // Only the caller's own companies; scoped by the membership relation.
        Route::get('/', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('/', [CompanyController::class, 'store'])->name('companies.store');

        Route::get('/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::put('/{company}', [CompanyController::class, 'update'])->name('companies.update');

        /*
         | Lifecycle. Deactivation replaces deletion because future accounting
         | records will reference this row.
         */
        Route::post('/{company}/activate', [CompanyController::class, 'activate'])
            ->name('companies.activate');
        Route::post('/{company}/deactivate', [CompanyController::class, 'deactivate'])
            ->name('companies.deactivate');

        // Select the caller's default company. Echoes the header to use next.
        Route::post('/{company}/switch', [CompanyContextController::class, 'switch'])
            ->name('companies.switch');

        // Membership management.
        Route::post('/{company}/members', [CompanyController::class, 'addMember'])
            ->name('companies.members.store');
        Route::delete('/{company}/members/{user}', [CompanyController::class, 'removeMember'])
            ->name('companies.members.destroy');
    });

/*
| The active company and its settings.
|
| `company.context` resolves the active company from the X-Company-Id header,
| falling back to the caller's default, and rejects the request if the caller
| is not a member or the company is inactive. Because the settings routes never
| accept a company id in the body, there is no id to tamper with.
*/
Route::middleware(['auth:api', 'auth.fresh', 'company.context', 'throttle:api'])
    ->prefix('company')
    ->group(function () {
        Route::get('/', [CompanyContextController::class, 'show'])->name('company.current');

        Route::get('/settings', [CompanySettingsController::class, 'show'])
            ->name('company.settings.show');
        Route::put('/settings', [CompanySettingsController::class, 'update'])
            ->name('company.settings.update');
    });

/*
|--------------------------------------------------------------------------
| Accounting (Phase 4)
|--------------------------------------------------------------------------
|
| Chart of accounts, accounting periods, journals and the ledger.
|
| Every route here sits behind `company.context`, so the active company is
| resolved and authorised before the controller runs. None of these endpoints
| accepts a company id: the scope comes from the caller's context, and route
| model binding for account/journal/period is scoped to that same company in
| AppServiceProvider. An id belonging to another company therefore 404s before
| any controller code runs.
|
| Journals keep draft and posting lifecycle on separate paths. PUT can never post
| a journal, and POST /post can never edit one - the split is enforced by which
| service each controller action calls, not by a status check inside a shared
| method.
*/
Route::middleware(['auth:api', 'auth.fresh', 'company.context', 'throttle:api'])
    ->group(function () {
        /*
        | Chart of accounts. `/types` is declared before `/{account}` so the
        | literal path is not swallowed by the wildcard parameter.
        */
        Route::prefix('accounts')->group(function () {
            Route::get('/types', [AccountController::class, 'types'])->name('accounts.types');

            Route::get('/', [AccountController::class, 'index'])->name('accounts.index');
            Route::post('/', [AccountController::class, 'store'])->name('accounts.store');
            Route::get('/{account}', [AccountController::class, 'show'])->name('accounts.show');
            Route::put('/{account}', [AccountController::class, 'update'])->name('accounts.update');
            Route::delete('/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

            Route::post('/{account}/activate', [AccountController::class, 'activate'])
                ->name('accounts.activate');
            Route::post('/{account}/deactivate', [AccountController::class, 'deactivate'])
                ->name('accounts.deactivate');
        });

        /*
        | Accounting periods. Phase 8 adds reopen as a separate, separately
        | authorized act: closing is the control, reopening is the privileged
        | undoing of it, and the two must be revocable independently.
        */
        Route::prefix('accounting/periods')->group(function () {
            Route::get('/', [AccountingPeriodController::class, 'index'])->name('accounting.periods.index');
            Route::post('/', [AccountingPeriodController::class, 'store'])->name('accounting.periods.store');
            Route::get('/{period}', [AccountingPeriodController::class, 'show'])->name('accounting.periods.show');
            Route::put('/{period}', [AccountingPeriodController::class, 'update'])->name('accounting.periods.update');
            Route::post('/{period}/close', [AccountingPeriodController::class, 'close'])
                ->name('accounting.periods.close');
            Route::post('/{period}/reopen', [AccountingPeriodController::class, 'reopen'])
                ->name('accounting.periods.reopen');
        });

        /*
        | Financial years (Phase 8). The year is the container periods live in and
        | the thing period generation walks, so its routes sit next to theirs.
        | Generation is a POST to a sub-resource rather than a field on the year
        | because it is an action that may be repeated, not a state to set.
        */
        Route::prefix('accounting/financial-years')->group(function () {
            Route::get('/', [FinancialYearController::class, 'index'])->name('accounting.financial_years.index');
            Route::post('/', [FinancialYearController::class, 'store'])->name('accounting.financial_years.store');
            Route::get('/{financialYear}', [FinancialYearController::class, 'show'])->name('accounting.financial_years.show');
            Route::put('/{financialYear}', [FinancialYearController::class, 'update'])->name('accounting.financial_years.update');
            Route::post('/{financialYear}/periods/generate', [FinancialYearController::class, 'generatePeriods'])
                ->name('accounting.financial_years.periods.generate');
            Route::post('/{financialYear}/close', [FinancialYearController::class, 'close'])
                ->name('accounting.financial_years.close');
        });

        /*
        | Journals. The store/update/delete actions manage drafts through
        | JournalService; /post goes through JournalPostingService. A posted
        | journal is immutable through every route above.
        */
        Route::prefix('journals')->group(function () {
            Route::get('/', [JournalController::class, 'index'])->name('journals.index');
            Route::post('/', [JournalController::class, 'store'])->name('journals.store');
            Route::get('/{journal}', [JournalController::class, 'show'])->name('journals.show');
            Route::put('/{journal}', [JournalController::class, 'update'])->name('journals.update');
            Route::delete('/{journal}', [JournalController::class, 'destroy'])->name('journals.destroy');
            Route::post('/{journal}/post', [JournalController::class, 'post'])->name('journals.post');
        });

        /*
        | Ledger reads. Derived from posted journal lines only; no write path
        | exists here, so no balance can be cached or overridden.
        */
        Route::prefix('accounting')->group(function () {
            Route::get('/trial-balance', [LedgerController::class, 'trialBalance'])
                ->name('accounting.trial-balance');

            Route::get('/accounts/{account}/balance', [LedgerController::class, 'accountBalance'])
                ->name('accounting.accounts.balance');

            /*
            | Reports (Phase 6). Read-only statements derived from posted
            | journals and Phase 5 allocations. No route writes anything and no
            | route accepts a company id; the scope comes from the caller's
            | context exactly as everywhere else in this group.
            */
            Route::prefix('reports')->group(function () {
                Route::get('/trial-balance', [ReportController::class, 'trialBalance'])
                    ->name('accounting.reports.trial-balance');

                Route::get('/general-ledger', [ReportController::class, 'generalLedger'])
                    ->name('accounting.reports.general-ledger');

                Route::get('/profit-loss', [ReportController::class, 'profitLoss'])
                    ->name('accounting.reports.profit-loss');

                Route::get('/balance-sheet', [ReportController::class, 'balanceSheet'])
                    ->name('accounting.reports.balance-sheet');

                Route::get('/customer-statement', [ReportController::class, 'customerStatement'])
                    ->name('accounting.reports.customer-statement');

                Route::get('/supplier-statement', [ReportController::class, 'supplierStatement'])
                    ->name('accounting.reports.supplier-statement');

                Route::get('/receivables', [ReportController::class, 'receivables'])
                    ->name('accounting.reports.receivables');

                Route::get('/payables', [ReportController::class, 'payables'])
                    ->name('accounting.reports.payables');

                Route::get('/receivables-aging', [ReportController::class, 'receivablesAging'])
                    ->name('accounting.reports.receivables-aging');

                Route::get('/payables-aging', [ReportController::class, 'payablesAging'])
                    ->name('accounting.reports.payables-aging');

                Route::get('/cash-bank', [ReportController::class, 'cashBank'])
                    ->name('accounting.reports.cash-bank');
            });
        });
    });

/*
|--------------------------------------------------------------------------
| Transactions (Phase 5)
|--------------------------------------------------------------------------
|
| Customers, suppliers, invoices, bills, receipts and payments.
|
| Every route sits behind `company.context`, and route model binding for
| customer/supplier/invoice/bill/receipt/payment is scoped to that same company in
| AppServiceProvider. Two consequences that hold by construction rather than by
| each controller remembering:
|
|   1. A document belonging to another company 404s before any controller,
|      policy or resource sees it - and 404 rather than 403, because a 403 would
|      confirm the id exists, which is itself a disclosure.
|   2. No endpoint accepts a company id. There is nothing to tamper with; the
|      scope comes from the caller's context.
|
| Lifecycle is on separate paths, exactly as for journals. PUT can never post a
| document, and POST /post can never edit one, because each route calls a service
| that has no path to the other operation. A posted document is immutable through
| every route below, and a draft's number is never returned to the sequence when
| the draft is deleted.
|
| Note on the missing `invoice_number`/`amount`-total inputs: no route accepts a
| client-supplied invoice number, status, journal id or computed total. They are
| not validated-and-ignored, they are absent from the request rules entirely, so
| there is no payload through which a client can set them.
*/
Route::middleware(['auth:api', 'auth.fresh', 'company.context', 'throttle:api'])
    ->group(function () {
        /*
        | Customers. No DELETE and no reactivate: a customer referenced by any
        | document must stay resolvable, so the lifecycle ends at deactivation.
        */
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])->name('customers.index');
            Route::post('/', [CustomerController::class, 'store'])->name('customers.store');
            Route::get('/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::put('/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::post('/{customer}/deactivate', [CustomerController::class, 'deactivate'])
                ->name('customers.deactivate');
        });

        /*
        | Suppliers. The counterpart of the customer routes above, with the same
        | absence of a delete.
        */
        Route::prefix('suppliers')->group(function () {
            Route::get('/', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::post('/', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::get('/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
            Route::put('/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
            Route::post('/{supplier}/deactivate', [SupplierController::class, 'deactivate'])
                ->name('suppliers.deactivate');
        });

        /*
        | Sales invoices.
        */
        Route::prefix('sales-invoices')->group(function () {
            Route::get('/', [SalesInvoiceController::class, 'index'])->name('sales.invoices.index');
            Route::post('/', [SalesInvoiceController::class, 'store'])->name('sales.invoices.store');
            Route::get('/{invoice}', [SalesInvoiceController::class, 'show'])->name('sales.invoices.show');
            Route::put('/{invoice}', [SalesInvoiceController::class, 'update'])->name('sales.invoices.update');
            Route::delete('/{invoice}', [SalesInvoiceController::class, 'destroy'])->name('sales.invoices.delete');
            Route::post('/{invoice}/post', [SalesInvoiceController::class, 'post'])->name('sales.invoices.post');
        });

        /*
        | Purchase bills.
        */
        Route::prefix('purchase-bills')->group(function () {
            Route::get('/', [PurchaseBillController::class, 'index'])->name('purchases.bills.index');
            Route::post('/', [PurchaseBillController::class, 'store'])->name('purchases.bills.store');
            Route::get('/{bill}', [PurchaseBillController::class, 'show'])->name('purchases.bills.show');
            Route::put('/{bill}', [PurchaseBillController::class, 'update'])->name('purchases.bills.update');
            Route::delete('/{bill}', [PurchaseBillController::class, 'destroy'])->name('purchases.bills.delete');
            Route::post('/{bill}/post', [PurchaseBillController::class, 'post'])->name('purchases.bills.post');
        });

        /*
        | Customer receipts. Two states only - DRAFT and POSTED. "Partially paid"
        | is a property of the invoices being settled, not of the money movement,
        | so a receipt never becomes PARTIALLY_PAID.
        */
        Route::prefix('customer-receipts')->group(function () {
            Route::get('/', [CustomerReceiptController::class, 'index'])->name('customer.receipts.index');
            Route::post('/', [CustomerReceiptController::class, 'store'])->name('customer.receipts.store');
            Route::get('/{receipt}', [CustomerReceiptController::class, 'show'])->name('customer.receipts.show');
            Route::put('/{receipt}', [CustomerReceiptController::class, 'update'])->name('customer.receipts.update');
            Route::delete('/{receipt}', [CustomerReceiptController::class, 'destroy'])->name('customer.receipts.delete');
            Route::post('/{receipt}/post', [CustomerReceiptController::class, 'post'])->name('customer.receipts.post');
        });

        /*
        | Supplier payments. The counterpart of the receipt routes above.
        */
        Route::prefix('supplier-payments')->group(function () {
            Route::get('/', [SupplierPaymentController::class, 'index'])->name('supplier.payments.index');
            Route::post('/', [SupplierPaymentController::class, 'store'])->name('supplier.payments.store');
            Route::get('/{payment}', [SupplierPaymentController::class, 'show'])->name('supplier.payments.show');
            Route::put('/{payment}', [SupplierPaymentController::class, 'update'])->name('supplier.payments.update');
            Route::delete('/{payment}', [SupplierPaymentController::class, 'destroy'])->name('supplier.payments.delete');
            Route::post('/{payment}/post', [SupplierPaymentController::class, 'post'])->name('supplier.payments.post');
        });
    });

/*
|--------------------------------------------------------------------------
| Cash & Banking (Phase 7)
|--------------------------------------------------------------------------
|
| Cash/bank transactions: deposits, withdrawals and transfers between the
| company's cash and bank accounts.
|
| Three create endpoints rather than one endpoint with a `transaction_type`
| field, and the reason is the accounting rather than the REST. The type decides
| which side of the account pair must itself be a cash/bank account, and an
| external deposit additionally needs an offset account that no part of this
| system can guess. If the type came from the payload, the eligibility rule would
| be validated against a client-supplied value; making the type a property of the
| route means the rule is applied to something the client did not choose.
|
| No route accepts a company id, a journal id, a balance or a status. The scope
| comes from the caller's company context, the journal is produced by the posting
| service, and the status is a lifecycle the client reads rather than sets.
|
| Lifecycle is on separate paths, as for every other document here: PUT can never
| post a transaction, POST /post can never edit one, because each calls a service
| that has no code path to the other operation.
|
| Cash/bank account *configuration* is deliberately not under this prefix. It acts
| on accounts in the chart of accounts, so it sits beside /api/accounts and binds
| the `account` parameter, which was already scoped to the active company in Phase
| 4. Splitting it out here would have needed a second binding for the same model
| to say the same thing twice.
|
| There is no cash/bank ledger endpoint. GET /api/accounting/reports/cash-bank in
| the group above is the read layer, and it already reflects every posted journal
| line; adding a second one would be a second implementation of the same running
| balance, free to drift from the first.
*/
Route::middleware(['auth:api', 'auth.fresh', 'company.context', 'throttle:api'])
    ->group(function () {
        /*
        | Cash and bank account configuration. Classification plus optional bank
        | metadata for an existing account; creating the account itself is
        | /api/accounts, because a bank account is an ordinary account in the
        | chart of accounts with a classification on it.
        |
        | No DELETE on the account itself and no route that deactivates it: both
        | belong to AccountController. The /bank-details route removes operational
        | metadata only, and leaves the classification alone.
        */
        Route::prefix('cash-bank-accounts')->group(function () {
            Route::get('/', [CashBankAccountController::class, 'index'])
                ->name('cash-bank.accounts.index');
            Route::put('/{account}', [CashBankAccountController::class, 'update'])
                ->name('cash-bank.accounts.update');
            Route::delete('/{account}/bank-details', [CashBankAccountController::class, 'destroyBankDetails'])
                ->name('cash-bank.accounts.bank-details.destroy');
            Route::post('/{account}/activate', [CashBankAccountController::class, 'activate'])
                ->name('cash-bank.accounts.activate');
            Route::post('/{account}/deactivate', [CashBankAccountController::class, 'deactivate'])
                ->name('cash-bank.accounts.deactivate');
        });

        /*
        | Cash and bank transactions.
        */
        Route::prefix('cash-bank-transactions')->group(function () {
            Route::get('/', [CashBankTransactionController::class, 'index'])
                ->name('cash-bank.transactions.index');

            // One path per movement type. The path names the accounting.
            Route::post('/deposits', [CashBankTransactionController::class, 'storeDeposit'])
                ->name('cash-bank.transactions.deposit');
            Route::post('/withdrawals', [CashBankTransactionController::class, 'storeWithdrawal'])
                ->name('cash-bank.transactions.withdrawal');
            Route::post('/transfers', [CashBankTransactionController::class, 'storeTransfer'])
                ->name('cash-bank.transactions.transfer');

            Route::get('/{transaction}', [CashBankTransactionController::class, 'show'])
                ->name('cash-bank.transactions.show');
            Route::put('/{transaction}', [CashBankTransactionController::class, 'update'])
                ->name('cash-bank.transactions.update');
            Route::delete('/{transaction}', [CashBankTransactionController::class, 'destroy'])
                ->name('cash-bank.transactions.delete');
            Route::post('/{transaction}/post', [CashBankTransactionController::class, 'post'])
                ->name('cash-bank.transactions.post');
        });
    });

/*
| Bank reconciliation (Phase 9).
|
| Same middleware stack as every other company-scoped module above:
| `auth:api` establishes the caller, `auth.fresh` re-checks the account and
| token version, `company.context` resolves and authorises the active company,
| and `throttle:api` bounds the surface. The policy and the FormRequest checks
| below still run - this group is what makes the identity those checks are made
| *against* exist at all, and what stops an unauthenticated request from reaching
| a controller that would then have no user to fail on.
|
| `reconciliation` and `item` are bound to the active company in
| AppServiceProvider, so an id from another tenant 404s before any method runs.
*/
Route::middleware(['auth:api', 'auth.fresh', 'company.context', 'throttle:api'])
    ->prefix('bank-reconciliations')
    ->group(function () {
        Route::get('/', [BankReconciliationController::class, 'index'])
            ->name('bank-reconciliations.index');
        Route::post('/', [BankReconciliationController::class, 'store'])
            ->name('bank-reconciliations.store');
        Route::get('/{reconciliation}', [BankReconciliationController::class, 'show'])
            ->name('bank-reconciliations.show');
        Route::put('/{reconciliation}', [BankReconciliationController::class, 'update'])
            ->name('bank-reconciliations.update');
        Route::delete('/{reconciliation}', [BankReconciliationController::class, 'destroy'])
            ->name('bank-reconciliations.destroy');

        Route::get('/{reconciliation}/movements', [BankReconciliationController::class, 'movements'])
            ->name('bank-reconciliations.movements');
        Route::post('/{reconciliation}/items', [BankReconciliationController::class, 'addItem'])
            ->name('bank-reconciliations.items.store');
        Route::delete('/{reconciliation}/items/{item}', [BankReconciliationController::class, 'removeItem'])
            ->name('bank-reconciliations.items.destroy');
        Route::post('/{reconciliation}/complete', [BankReconciliationController::class, 'complete'])
            ->name('bank-reconciliations.complete');
        Route::post('/{reconciliation}/reopen', [BankReconciliationController::class, 'reopen'])
            ->name('bank-reconciliations.reopen');
    });

/*
|--------------------------------------------------------------------------
| Taxes (Phase 10)
|--------------------------------------------------------------------------
|
| Tax configuration, the rate history of each tax, where its money posts, and
| one non-writing calculation endpoint.
|
| Same middleware stack as every other company-scoped module above, and the same
| tenant guarantee: `tax` and `rate` are bound to the active company in
| AppServiceProvider, so an id from another tenant 404s before any method runs.
| The company itself is never accepted as a field on any of these requests.
|
| `tax/calculate` is singular while the configuration routes are plural, because
| it is not a resource - it has no id, no persistence and no lifecycle, and giving
| it a plural path would imply one. It writes nothing: the brief is explicit that
| calling it must not persist anything, and the controller calls only
| TaxCalculationService, which has no database write on its path at all.
*/
Route::middleware(['auth:api', 'auth.fresh', 'company.context', 'throttle:api'])
    ->prefix('accounting')
    ->group(function () {
        Route::prefix('taxes')->group(function () {
            Route::get('/', [TaxController::class, 'index'])->name('accounting.taxes.index');
            Route::post('/', [TaxController::class, 'store'])->name('accounting.taxes.store');
            Route::get('/{tax}', [TaxController::class, 'show'])->name('accounting.taxes.show');
            Route::put('/{tax}', [TaxController::class, 'update'])->name('accounting.taxes.update');
            Route::delete('/{tax}', [TaxController::class, 'destroy'])->name('accounting.taxes.destroy');

            /*
            | Lifecycle, following the accounts and companies precedent. POST
            | because these are acts, not a state to be PUT wholesale: a
            | deactivation is an audited decision with its own authorization, and
            | the brief asks for it precisely where deletion would be unsafe.
            */
            Route::post('/{tax}/activate', [TaxController::class, 'activate'])
                ->name('accounting.taxes.activate');
            Route::post('/{tax}/deactivate', [TaxController::class, 'deactivate'])
                ->name('accounting.taxes.deactivate');

            Route::get('/{tax}/rates', [TaxController::class, 'rates'])->name('accounting.taxes.rates.index');
            Route::post('/{tax}/rates', [TaxController::class, 'storeRate'])->name('accounting.taxes.rates.store');
            Route::put('/{tax}/rates/{rate}', [TaxController::class, 'updateRate'])->name('accounting.taxes.rates.update');
            Route::delete('/{tax}/rates/{rate}', [TaxController::class, 'destroyRate'])->name('accounting.taxes.rates.destroy');

            Route::post('/{tax}/rates/{rate}/activate', [TaxController::class, 'activateRate'])
                ->name('accounting.taxes.rates.activate');
            Route::post('/{tax}/rates/{rate}/deactivate', [TaxController::class, 'deactivateRate'])
                ->name('accounting.taxes.rates.deactivate');

            Route::get('/{tax}/account-mapping', [TaxController::class, 'accountMapping'])
                ->name('accounting.taxes.account-mapping.show');
            Route::put('/{tax}/account-mapping', [TaxController::class, 'updateAccountMapping'])
                ->name('accounting.taxes.account-mapping.update');
        });

        /*
        | A calculation, not a write. POST because the amount, the taxes and the
        | date are all inputs that determine the answer, and there is no resource
        | with an identity to address afterwards - but nothing about it mutates
        | state, which is why it lives under the accounting prefix with the
        | configuration rather than with the reports.
        */
        Route::post('/tax/calculate', [TaxController::class, 'calculate'])
            ->name('accounting.tax.calculate');

        /*
        | Two read-only reports on posted documents' own tax snapshots. They sit
        | under the tax prefix rather than Phase 6's `reports` group because their
        | authorization differs: `accounting.tax.report.view`, not
        | `accounting.reports.view`. See TaxReportController for why that is not
        | folded together.
        */
        Route::prefix('tax-reports')->group(function () {
            Route::get('/summary', [TaxReportController::class, 'summary'])
                ->name('accounting.tax-reports.summary');

            Route::get('/by-tax', [TaxReportController::class, 'byTax'])
                ->name('accounting.tax-reports.by-tax');
        });
    });
