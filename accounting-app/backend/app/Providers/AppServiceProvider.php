<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankReconciliation;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\FinancialYear;
use App\Models\Journal;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\User;
use App\Policies\AccountingPeriodPolicy;
use App\Policies\AccountPolicy;
use App\Policies\BankReconciliationPolicy;
use App\Policies\CashBankTransactionPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\CustomerReceiptPolicy;
use App\Policies\JournalPolicy;
use App\Policies\PurchaseBillPolicy;
use App\Policies\SalesInvoicePolicy;
use App\Policies\SupplierPaymentPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\TaxPolicy;
use App\Policies\UserPolicy;
use App\Services\CompanyContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // scoped() rather than singleton(): one instance per request/cycle, so
        // a resolved company can never leak into a later request.
        $this->app->scoped(CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePolicies();
        $this->configureRateLimiting();
    }

    private function configurePolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Journal::class, JournalPolicy::class);
        Gate::policy(AccountingPeriod::class, AccountingPeriodPolicy::class);
        /*
         | FinancialYear reuses AccountingPeriodPolicy. The two models are one
         | hierarchy governed by one permission set, so a second policy class would
         | decide the same questions and the two would drift apart. The methods are
         | named per action (createYear, closeYear, ...) so the mapping stays
         | obvious at the call site.
         */
        Gate::policy(FinancialYear::class, AccountingPeriodPolicy::class);

        /*
         | Phase 5 policies.
         |
         | Registered explicitly for the same reason as Phase 4: a policy that is
         | discovered by naming convention is a policy that stops being applied the
         | moment the class is renamed or moved, and the failure is silent - the
         | ability simply returns false, or worse, an unguarded action.
         */
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(SalesInvoice::class, SalesInvoicePolicy::class);
        Gate::policy(PurchaseBill::class, PurchaseBillPolicy::class);
        Gate::policy(CustomerReceipt::class, CustomerReceiptPolicy::class);
        Gate::policy(SupplierPayment::class, SupplierPaymentPolicy::class);

        /*
         | Phase 7 policy.
         |
         | CashBankTransaction gets a policy of its own because it is its own model
         | and needs its own abilities. Cash/bank *account configuration* does not
         | get one: it acts on Account, which already has AccountPolicy, and a
         | policy is resolved per model class - a second policy for Account could
         | never be reached through authorize() and would look like it enforced
         | something while enforcing nothing. Those endpoints check their
         | permission directly, with the membership half of the check already
         | handled by the scoped `account` route binding below.
         */
        Gate::policy(CashBankTransaction::class, CashBankTransactionPolicy::class);
        Gate::policy(BankReconciliation::class, BankReconciliationPolicy::class);

        /*
         | Phase 10 policies.
         |
         | TaxPolicy is registered against two model classes, not one, because a
         | policy is resolved per model class: `authorize('updateRate', $rate)` on
         | a TaxRate looks for TaxRatePolicy, finds nothing, and a missing policy
         | is *unauthorized* - so registering only Tax would have made every rate
         | route fail closed. One policy holding both classes' abilities is the
         | alternative to two near-identical files, and the abilities deliberately
         | collapse onto the same permissions (see TaxPolicy's docblock).
         |
         | TaxAccountMapping is not registered: no route addresses a mapping
         | directly, every mapping is read and written through its parent tax.
         */
        Gate::policy(Tax::class, TaxPolicy::class);
        Gate::policy(TaxRate::class, TaxPolicy::class);

        /*
         | Accounting route binding.
         |
         | Registered here rather than in each controller so the tenant check
         | cannot be forgotten on a future accounting endpoint. It resolves the
         | company from the authenticated context - never from the route, the
         | body, or a query string.
         */
        $this->bindAccountingModelsToActiveCompany();
    }

    /**
     * Scope accounting route model binding to the active company.
     *
     * Without this, `GET /api/accounts/7` resolves account 7 regardless of which
     * company owns it, and a controller would be handed another tenant's row.
     * Scoping the lookup means the resolution itself fails and the route returns
     * 404 before any controller, policy or resource sees the object.
     *
     * 404 rather than 403 is deliberate: a 403 would confirm the id exists,
     * which is itself a disclosure. "Not found" is equally true of every id the
     * caller does not own.
     *
     * Phase 5 models are bound here for the same reason as Phase 4's, and with
     * the same property that matters most: a Customer, SalesInvoice or
     * CustomerReceipt resolved this way is already known to belong to the active
     * company, so no controller or service needs to re-assert it. The
     * cross-tenant case - a receipt naming an invoice from another company - is
     * caught by a different mechanism entirely, because the allocation's own
     * foreign key is not bound by the route.
     */
    private function bindAccountingModelsToActiveCompany(): void
    {
        $scoped = fn (string $parameter, string $modelClass) => Route::bind(
            $parameter,
            function ($value) use ($modelClass) {
                $company = app(CompanyContext::class)->getOrFail();

                return $modelClass::query()
                    ->where('company_id', $company->getKey())
                    ->whereKey($value)
                    ->first()
                    ?? throw (new ModelNotFoundException)->setModel($modelClass);
            }
        );

        $scoped('account', Account::class);
        $scoped('journal', Journal::class);
        $scoped('period', AccountingPeriod::class);

        // Phase 8. Same property as `period`: a financial year reaching a
        // controller is already known to belong to the active company, so no
        // controller re-asserts it and a foreign id 404s before any method runs.
        $scoped('financialYear', FinancialYear::class);

        // Phase 5.
        $scoped('customer', Customer::class);
        $scoped('supplier', Supplier::class);
        $scoped('invoice', SalesInvoice::class);
        $scoped('bill', PurchaseBill::class);
        $scoped('receipt', CustomerReceipt::class);
        $scoped('payment', SupplierPayment::class);

        // Phase 7. Same property as Phase 5's bindings: a CashBankTransaction
        // resolved here is already known to belong to the active company, so no
        // controller or service re-asserts it and a cross-company id 404s before
        // the controller runs.
        $scoped('transaction', CashBankTransaction::class);
        $scoped('reconciliation', BankReconciliation::class);
        $scoped('item', BankReconciliationItem::class);

        // Phase 10. Both tables carry company_id, so the same property holds: a Tax
        // or TaxRate resolved here is already known to belong to the active
        // company. `rate` is additionally checked against its parent tax in the
        // controller, because being in the same company is not the same as being
        // the rate of this tax - and the route binding cannot see the relationship.
        $scoped('tax', Tax::class);
        $scoped('rate', TaxRate::class);
    }

    /**
     * Rate limits for authentication-sensitive endpoints.
     *
     * Login and forgot-password are additionally keyed by a hash of the
     * submitted identity, so a distributed attacker is still bounded per
     * account and a single attacker cannot lock a real user out.
     */
    private function configureRateLimiting(): void
    {
        // Broad ceiling for the authenticated API surface.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Coarse ceiling shared by the public auth endpoints.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(30)
            ->by($request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip()));

        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('forgot-ip:'.$request->ip()),
                Limit::perMinute(1)->by('forgot-account:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('reset-password', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip()));

        RateLimiter::for('change-password', fn (Request $request) => Limit::perMinute(5)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('verify-email', fn (Request $request) => Limit::perMinute(2)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
