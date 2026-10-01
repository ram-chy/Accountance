<?php

namespace Tests;

use App\Enums\JournalStatus;
use App\Enums\PaymentStatus;
use App\Enums\PeriodStatus;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Journal;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyService;
use App\Services\RolePermissionSynchroniser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user holding a single role, ensuring the role exists first.
     */
    protected function createUserWithRole(RoleName|string $role, array $attributes = []): User
    {
        $this->ensureRolesExist();

        $user = User::factory()->create($attributes);

        $user->assignRole($role instanceof RoleName ? $role->value : $role);

        return $user->fresh();
    }

    /**
     * Create the reference roles and permissions, once per test.
     *
     * Roles are reference data created in production by `app:sync-roles`, not by
     * a seeder. Tests that never touch authorization must not pay for this, so
     * it is lazy rather than part of the base setUp().
     *
     * The flag is per instance, not static: RefreshDatabase rolls the schema
     * back between tests, so a cached "already synced" flag would leave later
     * tests querying roles that no longer exist.
     */
    protected function ensureRolesExist(): void
    {
        if ($this->rolesSynchronised) {
            return;
        }

        $synchroniser = app(RolePermissionSynchroniser::class);
        $synchroniser->ensureBaselineRoles();
        $synchroniser->sync();

        $this->rolesSynchronised = true;
    }

    /**
     * Whether the reference roles have been created for the current test.
     */
    protected bool $rolesSynchronised = false;

    /**
     * Create a company with a settings row, and make it the user's default.
     */
    protected function createCompanyFor(User $user, array $attributes = [], bool $isDefault = true): Company
    {
        $this->ensureRolesExist();

        $company = Company::factory()->create($attributes);
        $company->settings()->create();

        app(CompanyService::class)->attach($company, $user, $isDefault);

        return $company->refresh();
    }

    /**
     * Create a company the given user does NOT belong to.
     */
    protected function createUnrelatedCompany(array $attributes = []): Company
    {
        return Company::factory()->create($attributes);
    }

    /**
     * Add an existing user to an existing company without making it their default.
     *
     * Used when a test needs a second actor inside a company that already exists
     * - typically an Admin who sets up the data a Manager then tries to change.
     * `createCompanyFor` cannot be used for that because it would create a second
     * company, and the second actor's token would then be refused by
     * company.context for not being a member.
     */
    protected function addMemberTo(Company $company, User $user): User
    {
        $this->ensureRolesExist();

        app(CompanyService::class)->attach($company, $user);

        return $user->fresh();
    }

    /**
     * Send the X-Company-Id header for the next request.
     *
     * withHeader() appends to the default header bag rather than replacing it,
     * and actingAsJwt() also uses that bag, so the token and the company header
     * must both be applied through the same mechanism. Calling the two helpers
     * in sequence is safe; setting $this->defaultHeaders by hand is not.
     */
    protected function withCompanyContext(Company $company): static
    {
        return $this->withHeader(CompanyContext::HEADER, (string) $company->getKey());
    }

    /**
     * Issue a JWT for the given user, as the login endpoint would.
     */
    protected function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    /**
     * Authenticate the next request as the given user.
     */
    protected function actingAsJwt(User $user): static
    {
        $this->resetJwtAuthenticationState();

        return $this->withToken($this->tokenFor($user));
    }

    /**
     * Drop the authentication state that earlier requests left behind.
     *
     * Production gets a fresh application per request; a test reuses one
     * application for every request it makes. Two singletons therefore leak the
     * previous request's identity forward:
     *
     *  - `tymon.jwt` (the JWT instance the guard uses) memoises the token it
     *   parsed in `JWT::$token`, and `setRequest()` does not clear it. Without a
     *   reset the *first* token a test sends authenticates every later request,
     *   so a test that switches actor mid-way silently keeps making requests as
     *   the original user.
     *  - `AuthManager` memoises its guards, and `JWTGuard` memoises the user it
     *   resolved.
     *
     * The `JWTAuth` facade keeps its own resolved instance, which would otherwise
     * be a *different* JWT object from the one the guard just authenticated with
     * - `JWTAuth::getToken()` in a logout handler would then return a token from
     * an earlier request. Clearing it keeps the facade and the guard in step.
     */
    protected function resetJwtAuthenticationState(): void
    {
        foreach (['tymon.jwt', 'tymon.jwt.auth'] as $binding) {
            $this->app->forgetInstance($binding);
            JWTAuth::clearResolvedInstance($binding);
        }

        $this->app['auth']->forgetGuards();
    }

    /*
    |--------------------------------------------------------------------------
    | Accounting test helpers (Phase 4)
    |--------------------------------------------------------------------------
    */

    /**
     * A minimal balanced pair of accounts for a company: one asset, one revenue.
     *
     * Returns the pair so a test can use them directly. Both are created through
     * the factory rather than hard-coded ids, so several companies in one test
     * cannot collide.
     *
     * @return array{0: Account, 1: Account}
     */
    protected function makeCashAndRevenueAccounts(Company $company): array
    {
        return [
            Account::factory()->for($company)->asset()->create([
                'code' => '1000',
                'name' => 'Cash',
            ]),
            Account::factory()->for($company)->revenue()->create([
                'code' => '4000',
                'name' => 'Sales Revenue',
            ]),
        ];
    }

    /**
     * Create an open period covering a date.
     */
    protected function makePeriodFor(
        Company $company,
        string $date,
        string $name = 'Test Period',
        bool $closed = false
    ): AccountingPeriod {
        $day = Carbon::parse($date);

        $period = AccountingPeriod::factory()->for($company)->create([
            'name' => $name,
            'start_date' => $day->copy()->startOfMonth()->toDateString(),
            'end_date' => $day->copy()->endOfMonth()->toDateString(),
            'status' => PeriodStatus::Open->value,
        ]);

        if ($closed) {
            $period->forceFill(['status' => PeriodStatus::Closed->value])->save();
        }

        return $period->refresh();
    }

    /**
     * Create a draft journal through the API.
     *
     * The counterpart to postJournal(): several tests need a journal that has
     * deliberately *not* been posted - to prove drafts are invisible to the
     * ledger, to attempt a second post, or to check that editing a posted entry
     * is refused. This helper returns the model without calling /post.
     *
     * The `lines` key is accepted as a full override so a test can submit a
     * deliberately malformed entry - unbalanced, one-sided, zero-valued - without
     * the helper second-guessing it.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createDraftJournal(
        User $user,
        Company $company,
        Account $debitAccount,
        Account $creditAccount,
        string $amount = '1000.00',
        string $date = '2027-01-15',
        array $overrides = []
    ): Journal {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', array_merge([
                'journal_date' => $date,
                'description' => 'Test draft',
                'lines' => [
                    ['account_id' => $debitAccount->getKey(), 'debit' => $amount, 'credit' => '0'],
                    ['account_id' => $creditAccount->getKey(), 'debit' => '0', 'credit' => $amount],
                ],
            ], $overrides));

        $response->assertSuccessful();

        return Journal::findOrFail($response->json('data.id'));
    }

    /**
     * The last response's JSON error message, for assertions on copy.
     */
    protected function responseMessage(TestResponse $response): string
    {
        return (string) $response->json('message');
    }

    /**
     * The last response's JSON validation errors, flattened to "field => message".
     *
     * @return array<string, string>
     */
    protected function responseErrors(TestResponse $response): array
    {
        $flat = [];

        foreach ((array) $response->json('errors', []) as $field => $messages) {
            $flat[$field] = (string) (is_array($messages) ? ($messages[0] ?? '') : $messages);
        }

        return $flat;
    }

    /**
     * Post a balanced journal through the API and assert it was created.
     *
     * Most accounting tests need a posted entry as a precondition, and routing
     * it through the real endpoint means the numbering sequence, the period
     * lookup and the posting lock are all exercised rather than mocked. It also
     * keeps a test from silently passing against a journal the system could not
     * actually post.
     */
    protected function postJournal(
        User $user,
        Company $company,
        Account $debitAccount,
        Account $creditAccount,
        string $amount = '1000.00',
        string $date = '2027-01-15',
        array $overrides = []
    ): Journal {
        $this->makePeriodFor($company, $date, 'P '.substr($date, 0, 7).uniqid());

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', array_merge([
                'journal_date' => $date,
                'description' => 'Test entry',
                'lines' => [
                    ['account_id' => $debitAccount->getKey(), 'debit' => $amount, 'credit' => '0'],
                    ['account_id' => $creditAccount->getKey(), 'debit' => '0', 'credit' => $amount],
                ],
            ], $overrides));

        $response->assertSuccessful();

        $journal = Journal::findOrFail($response->json('data.id'));

        // Creating a journal only produces a draft; the docblock promises a
        // posted entry, so the second call has to happen. Tests that assert
        // on ledger balances depend on the rows actually being posted.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', JournalStatus::Posted->value);

        return $journal->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Transaction test helpers (Phase 5)
    |--------------------------------------------------------------------------
    |
    | Every helper here goes through the real API rather than calling a service
    | directly. That is a deliberate cost: a test that called SalesInvoiceService
    | would pass while the route, the FormRequest and the policy were broken, and
    | those three are where a company-scope or permission mistake would actually
    | live. Going in through HTTP means the thing under test is the endpoint.
    */

    /**
     * The accounts a Phase 5 test needs, keyed by role.
     *
     * Built through factories with explicit codes rather than a chart-of-accounts
     * seeder, so two companies in one test cannot collide and no test depends on
     * which account ids a previous test happened to allocate.
     *
     * @return array<string, Account>
     */
    protected function makeTransactionAccounts(Company $company): array
    {
        return [
            'receivable' => Account::factory()->for($company)->asset()->create([
                'code' => '1100', 'name' => 'Accounts Receivable',
            ]),
            'cash' => Account::factory()->for($company)->asset()->create([
                'code' => '1000', 'name' => 'Cash',
            ]),
            'payable' => Account::factory()->for($company)->liability()->create([
                'code' => '2000', 'name' => 'Accounts Payable',
            ]),
            'tax_payable' => Account::factory()->for($company)->liability()->create([
                'code' => '2100', 'name' => 'Sales Tax Payable',
            ]),
            'input_tax' => Account::factory()->for($company)->asset()->create([
                'code' => '1200', 'name' => 'Input Tax Recoverable',
            ]),
            'revenue' => Account::factory()->for($company)->revenue()->create([
                'code' => '4000', 'name' => 'Sales Revenue',
            ]),
            'expense' => Account::factory()->for($company)->expense()->create([
                'code' => '5000', 'name' => 'Purchases Expense',
            ]),
        ];
    }

    /**
     * Create a customer through the API.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createCustomer(
        User $user,
        Company $company,
        array $accounts,
        array $overrides = []
    ): Customer {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customers', array_merge([
                'customer_code' => 'C'.uniqid(),
                'name' => 'Test Customer',
                'receivable_account_id' => $accounts['receivable']->getKey(),
            ], $overrides));

        $response->assertSuccessful();

        return Customer::findOrFail($response->json('data.id'));
    }

    /**
     * Create a supplier through the API.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createSupplier(
        User $user,
        Company $company,
        array $accounts,
        array $overrides = []
    ): Supplier {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/suppliers', array_merge([
                'supplier_code' => 'S'.uniqid(),
                'name' => 'Test Supplier',
                'payable_account_id' => $accounts['payable']->getKey(),
            ], $overrides));

        $response->assertSuccessful();

        return Supplier::findOrFail($response->json('data.id'));
    }

    /**
     * Create a draft sales invoice through the API.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createDraftInvoice(
        User $user,
        Company $company,
        Customer $customer,
        array $accounts,
        array $overrides = []
    ): SalesInvoice {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', array_merge([
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [
                    [
                        'description' => 'Widget',
                        'quantity' => '2',
                        'unit_price' => '100.00',
                        'discount' => '0',
                        'tax_rate' => '0',
                        'revenue_account_id' => $accounts['revenue']->getKey(),
                    ],
                ],
            ], $overrides));

        $response->assertSuccessful();

        return SalesInvoice::findOrFail($response->json('data.id'));
    }

    /**
     * Create and post a sales invoice, returning the posted model.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function postInvoice(
        User $user,
        Company $company,
        Customer $customer,
        array $accounts,
        string $date = '2027-01-10',
        array $overrides = []
    ): SalesInvoice {
        $this->makePeriodFor($company, $date, 'P '.substr($date, 0, 7).uniqid());

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts, array_merge(
            ['invoice_date' => $date, 'due_date' => Carbon::parse($date)->addMonth()->toDateString()],
            $overrides,
        ));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', TransactionStatus::Posted->value);

        return $invoice->refresh();
    }

    /**
     * Create and post a purchase bill, returning the posted model.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function postBill(
        User $user,
        Company $company,
        Supplier $supplier,
        array $accounts,
        string $date = '2027-01-10',
        array $overrides = []
    ): PurchaseBill {
        $this->makePeriodFor($company, $date, 'P '.substr($date, 0, 7).uniqid());

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', array_merge([
                'supplier_id' => $supplier->getKey(),
                'bill_date' => $date,
                'due_date' => Carbon::parse($date)->addMonth()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Materials',
                        'quantity' => '1',
                        'unit_cost' => '500.00',
                        'discount' => '0',
                        'tax_rate' => '0',
                        'expense_account_id' => $accounts['expense']->getKey(),
                    ],
                ],
            ], $overrides));

        $response->assertSuccessful();

        $bill = PurchaseBill::findOrFail($response->json('data.id'));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/purchase-bills/{$bill->getKey()}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', TransactionStatus::Posted->value);

        return $bill->refresh();
    }

    /**
     * Create and post a customer receipt, returning the posted model.
     *
     * Allocations must total the receipt amount: a receipt is fully allocated in
     * Phase 5, and the helper asserts the POST succeeded so a test cannot go on
     * to assert against a receipt the system refused.
     *
     * @param  array<int, array{sales_invoice_id: int, amount: string}>  $allocations
     */
    protected function postCustomerReceipt(
        User $user,
        Company $company,
        Customer $customer,
        array $accounts,
        string $amount,
        array $allocations,
        string $date = '2027-01-20'
    ): CustomerReceipt {
        $this->makePeriodFor($company, $date, 'P '.substr($date, 0, 7).uniqid());

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => $date,
                'amount' => $amount,
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => $allocations,
            ]);

        $response->assertSuccessful();

        $receipt = CustomerReceipt::findOrFail($response->json('data.id'));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/customer-receipts/{$receipt->getKey()}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PaymentStatus::Posted->value);

        return $receipt->refresh();
    }

    /**
     * Create and post a supplier payment, returning the posted model.
     *
     * @param  array<int, array{purchase_bill_id: int, amount: string}>  $allocations
     */
    protected function postSupplierPayment(
        User $user,
        Company $company,
        Supplier $supplier,
        array $accounts,
        string $amount,
        array $allocations,
        string $date = '2027-01-20'
    ): SupplierPayment {
        $this->makePeriodFor($company, $date, 'P '.substr($date, 0, 7).uniqid());

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => $date,
                'amount' => $amount,
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => $allocations,
            ]);

        $response->assertSuccessful();

        $payment = SupplierPayment::findOrFail($response->json('data.id'));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/supplier-payments/{$payment->getKey()}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PaymentStatus::Posted->value);

        return $payment->refresh();
    }

    /**
     * Assert that a write is refused by the database itself.
     *
     * For the Phase 5 integrity tests, which deliberately bypass the services
     * that would normally catch a bad write in order to prove the storage layer
     * also refuses it. Laravel has no built-in assertion for this, so the write
     * is attempted and a QueryException is required.
     *
     * MySQL rolls back the failing statement rather than the whole transaction,
     * so the test can keep asserting against the same database afterwards.
     *
     * @param  \Closure(): mixed  $write
     */
    protected function assertDatabaseIntegrityViolation(\Closure $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertNotEmpty(
                $e->getMessage(),
                'The write failed for the wrong reason.'
            );

            return;
        }

        $this->fail('The write was accepted, but the database was expected to refuse it.');
    }
}
