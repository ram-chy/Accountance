<?php

namespace Tests\Feature\Transactions;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 5 role matrix, exercised through real HTTP requests.
 *
 * The spec requires the matrix to be tested that way rather than by calling
 * policies directly, and for a good reason: a permission can be granted to a role
 * and still be unreachable in practice if a FormRequest forgets to authorize,
 * or if a controller reads the wrong policy. Only a request through the whole
 * middleware stack proves the permission is enforced.
 *
 * Data providers rather than one test per cell, so a change to the matrix shows
 * up as a named failure rather than a wall of near-identical methods.
 */
class TransactionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, array<string, Account>>
     */
    private array $accountsByCompany = [];

    /**
     * The view permissions every role in the matrix can hold.
     *
     * Manager and Staff differ: Manager reads, Staff does not.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function readProvider(): array
    {
        return [
            'admin reads everything' => [RoleName::Admin->value, [
                '/api/customers', '/api/suppliers', '/api/sales-invoices',
                '/api/purchase-bills', '/api/customer-receipts', '/api/supplier-payments',
            ]],
            'accountant reads everything' => [RoleName::Accountant->value, [
                '/api/customers', '/api/suppliers', '/api/sales-invoices',
                '/api/purchase-bills', '/api/customer-receipts', '/api/supplier-payments',
            ]],
            'manager reads documents' => [RoleName::Manager->value, [
                '/api/customers', '/api/suppliers', '/api/sales-invoices',
                '/api/purchase-bills', '/api/customer-receipts', '/api/supplier-payments',
            ]],
            'staff reads nothing' => [RoleName::Staff->value, [
                '/api/customers', '/api/suppliers', '/api/sales-invoices',
                '/api/purchase-bills', '/api/customer-receipts', '/api/supplier-payments',
            ]],
        ];
    }

    /**
     * The write permissions, which only Admin and Accountant hold.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function writeProvider(): array
    {
        return [
            'admin writes everything' => [RoleName::Admin->value, [
                '/api/customers', '/api/suppliers', '/api/sales-invoices',
                '/api/purchase-bills', '/api/customer-receipts', '/api/supplier-payments',
            ]],
            'accountant writes everything' => [RoleName::Accountant->value, [
                '/api/customers', '/api/suppliers', '/api/sales-invoices',
                '/api/purchase-bills', '/api/customer-receipts', '/api/supplier-payments',
            ]],
            'manager writes nothing' => [RoleName::Manager->value, [
                '/api/customers', '/api/suppliers', '/api/sales-invoices',
                '/api/purchase-bills', '/api/customer-receipts', '/api/supplier-payments',
            ]],
            'staff writes nothing' => [RoleName::Staff->value, [
                '/api/customers', '/api/suppliers', '/api/sales-invoices',
                '/api/purchase-bills', '/api/customer-receipts', '/api/supplier-payments',
            ]],
        ];
    }

    #[Test]
    #[DataProvider('readProvider')]
    public function list_access_follows_the_matrix(string $role, array $endpoints): void
    {
        [$user, $company] = $this->actor($role);

        foreach ($endpoints as $endpoint) {
            $response = $this->actingAsJwt($user)->withCompanyContext($company)->getJson($endpoint);

            $mayRead = in_array($role, [RoleName::Admin->value, RoleName::Accountant->value, RoleName::Manager->value], true);

            $mayRead
                ? $response->assertSuccessful()
                : $response->assertForbidden();
        }
    }

    #[Test]
    #[DataProvider('writeProvider')]
    public function create_access_follows_the_matrix(string $role, array $endpoints): void
    {
        [$user, $company] = $this->actor($role);

        foreach ($endpoints as $endpoint) {
            $response = $this->actingAsJwt($user)
                ->withCompanyContext($company)
                ->postJson($endpoint, $this->payloadFor($endpoint, $user, $company));

            $mayWrite = in_array($role, [RoleName::Admin->value, RoleName::Accountant->value], true);

            $mayWrite
                ? $response->assertSuccessful()
                : $response->assertForbidden();
        }
    }

    #[Test]
    public function only_admin_and_accountant_may_post_a_document(): void
    {
        foreach ([RoleName::Admin, RoleName::Accountant, RoleName::Manager, RoleName::Staff] as $role) {
            [$user, $company] = $this->actor($role->value);
            $this->makePeriodFor($company, '2027-01-10', 'Post auth '.uniqid());

            $accounts = $this->accountsFor($company);
            $customer = Customer::factory()->for($company)->create([
                'receivable_account_id' => $accounts['receivable']->getKey(),
            ]);

            $invoice = SalesInvoice::factory()->for($company)->for($customer)->create([
                'status' => TransactionStatus::Draft->value,
                'invoice_date' => '2027-01-10',
            ]);
            $invoice->lines()->create([
                'line_number' => 1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '0',
                'tax_amount' => '0',
                'line_total' => '100.00',
                'revenue_account_id' => $accounts['revenue']->getKey(),
            ]);

            $response = $this->actingAsJwt($user)
                ->withCompanyContext($company)
                ->postJson("/api/sales-invoices/{$invoice->getKey()}/post");

            $mayPost = in_array($role, [RoleName::Admin, RoleName::Accountant], true);

            $mayPost
                ? $response->assertSuccessful()
                : $response->assertForbidden();
        }
    }

    #[Test]
    public function only_admin_and_accountant_may_deactivate_a_master_record(): void
    {
        foreach ([RoleName::Admin, RoleName::Accountant, RoleName::Manager, RoleName::Staff] as $role) {
            [$user, $company] = $this->actor($role->value);

            $customer = Customer::factory()->for($company)->create();
            $supplier = Supplier::factory()->for($company)->create();

            foreach (["/api/customers/{$customer->getKey()}/deactivate", "/api/suppliers/{$supplier->getKey()}/deactivate"] as $endpoint) {
                $response = $this->actingAsJwt($user)
                    ->withCompanyContext($company)
                    ->postJson($endpoint);

                $mayDeactivate = in_array($role, [RoleName::Admin, RoleName::Accountant], true);

                $mayDeactivate
                    ? $response->assertSuccessful()
                    : $response->assertForbidden();
            }
        }
    }

    #[Test]
    public function a_manager_cannot_edit_a_document_it_can_read(): void
    {
        [$user, $company] = $this->actor(RoleName::Manager->value);
        $customer = Customer::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create([
            'status' => TransactionStatus::Draft->value,
        ]);

        // Readable, but a Manager's job is reporting rather than data entry.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/sales-invoices/{$invoice->getKey()}", ['notes' => 'Changed'])
            ->assertForbidden();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertForbidden();
    }

    #[Test]
    public function the_receipt_and_payment_update_permissions_exist(): void
    {
        /*
         * The spec's suggested permission list has no update permission for
         * receipts or payments, but both are editable while draft and their
         * document numbers are never reused - so without one, a typo in a draft
         * amount could only be fixed by deleting and reissuing, which burns a
         * number permanently. This asserts the permissions are registered, so
         * the intentional difference from the suggested list stays visible.
         */
        $this->assertContains(PermissionName::CustomerReceiptsUpdate->value, array_column(PermissionName::cases(), 'value'));
        $this->assertContains(PermissionName::SupplierPaymentsUpdate->value, array_column(PermissionName::cases(), 'value'));
    }

    #[Test]
    public function a_user_who_is_not_a_company_member_is_refused(): void
    {
        $outsider = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createUnrelatedCompany();

        // The token is valid and the role is the most permissive one there is,
        // but the company context is not the user's, so the request never reaches
        // a policy - it is refused at company membership.
        $this->actingAsJwt($outsider)
            ->withCompanyContext($company)
            ->getJson('/api/sales-invoices')
            ->assertForbidden();
    }

    #[Test]
    public function a_permission_is_checked_against_the_active_company_not_a_global_grant(): void
    {
        [$user] = $this->actor(RoleName::Staff->value);
        $company = $this->createUnrelatedCompany();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/sales-invoices')
            ->assertForbidden();
    }

    #[Test]
    public function a_posted_document_cannot_be_deleted_even_by_an_admin(): void
    {
        [$user, $company] = $this->actor(RoleName::Admin->value);
        $this->makePeriodFor($company, '2027-01-10', 'Posted immutability '.uniqid());

        $accounts = $this->makeTransactionAccounts($company);
        $customer = Customer::factory()->for($company)->create([
            'receivable_account_id' => $accounts['receivable']->getKey(),
        ]);

        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create([
            'status' => TransactionStatus::Draft->value,
            'invoice_date' => '2027-01-10',
        ]);
        $invoice->lines()->create([
            'line_number' => 1,
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'line_total' => '100.00',
            'revenue_account_id' => $accounts['revenue']->getKey(),
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertSuccessful();

        // 422 rather than 403: the Admin holds the delete permission, and the
        // document's state is what refuses it.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertUnprocessable();
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function actor(string $role): array
    {
        $user = $this->createUserWithRole($role);

        return [$user, $this->createCompanyFor($user)];
    }

    /**
     * One set of accounts per company, cached across the endpoints of a data set.
     *
     * makeTransactionAccounts uses fixed codes because tests need to know which
     * account is which. The accounts table is unique on (company_id, code), so
     * calling it once per endpoint in the same company would collide - the set is
     * memoised per company instead of rebuilt.
     *
     * @return array<string, Account>
     */
    private function accountsFor(Company $company): array
    {
        return $this->accountsByCompany[$company->getKey()] ??= $this->makeTransactionAccounts($company);
    }

    /**
     * The smallest valid payload for each create endpoint.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(string $endpoint, User $user, Company $company): array
    {
        $accounts = $this->accountsFor($company);

        return match (true) {
            $endpoint === '/api/customers' => [
                'customer_code' => 'AUTH'.uniqid(),
                'name' => 'Auth Customer',
                'receivable_account_id' => $accounts['receivable']->getKey(),
            ],
            $endpoint === '/api/suppliers' => [
                'supplier_code' => 'AUTH'.uniqid(),
                'name' => 'Auth Supplier',
                'payable_account_id' => $accounts['payable']->getKey(),
            ],
            $endpoint === '/api/sales-invoices' => [
                'customer_id' => Customer::factory()->for($company)->create([
                    'receivable_account_id' => $accounts['receivable']->getKey(),
                ])->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_price' => '100.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ],
            $endpoint === '/api/purchase-bills' => [
                'supplier_id' => Supplier::factory()->for($company)->create([
                    'payable_account_id' => $accounts['payable']->getKey(),
                ])->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '100.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ],
            $endpoint === '/api/customer-receipts' => $this->receiptPayload($company, $accounts),
            default => $this->paymentPayload($company, $accounts),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptPayload(Company $company, array $accounts): array
    {
        $customer = Customer::factory()->for($company)->create([
            'receivable_account_id' => $accounts['receivable']->getKey(),
        ]);

        $invoice = $this->postedInvoice($company, $customer, $accounts);

        return [
            'customer_id' => $customer->getKey(),
            'receipt_date' => '2027-01-20',
            'amount' => '100.00',
            'payment_account_id' => $accounts['cash']->getKey(),
            'allocations' => [['sales_invoice_id' => $invoice->getKey(), 'amount' => '100.00']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Company $company, array $accounts): array
    {
        $supplier = Supplier::factory()->for($company)->create([
            'payable_account_id' => $accounts['payable']->getKey(),
        ]);

        // grand_total is what the outstanding balance is measured from, so a
        // factory-built bill needs one for the allocation to fit.
        $bill = PurchaseBill::factory()->for($company)->for($supplier)->create([
            'status' => TransactionStatus::Posted->value,
            'bill_date' => '2027-01-10',
            'grand_total' => '100.0000',
        ]);

        return [
            'supplier_id' => $supplier->getKey(),
            'payment_date' => '2027-01-20',
            'amount' => '100.00',
            'payment_account_id' => $accounts['cash']->getKey(),
            'allocations' => [['purchase_bill_id' => $bill->getKey(), 'amount' => '100.00']],
        ];
    }

    /**
     * A posted invoice, so a receipt payload has something it may allocate to.
     */
    private function postedInvoice(Company $company, Customer $customer, array $accounts): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create([
            'status' => TransactionStatus::Posted->value,
            'invoice_date' => '2027-01-10',
            'grand_total' => '100.0000',
        ]);

        $invoice->lines()->create([
            'line_number' => 1,
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'line_total' => '100.00',
            'revenue_account_id' => $accounts['revenue']->getKey(),
        ]);

        return $invoice;
    }
}
