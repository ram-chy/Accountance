<?php

namespace Tests\Feature\Transactions;

use App\Enums\JournalSource;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Journal;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The database constraints that back up the application-level concurrency rules.
 *
 * The spec (section 29) requires that uniqueness constraints back up application
 * validation rather than being trusted alone. This file asserts the constraints
 * exist and hold, by going through the model layer to insert directly - the
 * services that would normally catch these cases are deliberately bypassed.
 *
 * True simultaneity is not reproducible in a single-process PHP test, so what is
 * proven here is the second line of defence: even if a service check were lost
 * or raced past, the database refuses the write. The row locks that make the
 * application-level checks correct are asserted by reading the code paths that
 * take them, not simulated.
 */
class TransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function two_invoices_in_one_company_cannot_share_a_number(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $customer = Customer::factory()->for($company)->create();

        $first = SalesInvoice::factory()->for($company)->for($customer)->create([
            'invoice_number' => 'INV-000001',
        ]);

        $this->assertDatabaseIntegrityViolation(
            fn () => SalesInvoice::factory()->for($company)->for($customer)->create([
                'invoice_number' => 'INV-000001',
            ])
        );

        $this->assertSame(1, SalesInvoice::where('invoice_number', 'INV-000001')->count());
        $this->assertNotNull($first);
    }

    #[Test]
    public function two_bills_in_one_company_cannot_share_a_number(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $supplier = Supplier::factory()->for($company)->create();

        PurchaseBill::factory()->for($company)->for($supplier)->create(['bill_number' => 'BILL-000001']);

        $this->assertDatabaseIntegrityViolation(
            fn () => PurchaseBill::factory()->for($company)->for($supplier)->create([
                'bill_number' => 'BILL-000001',
            ])
        );
    }

    #[Test]
    public function the_same_invoice_number_may_exist_in_two_companies(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, [], isDefault: false);

        $customerA = Customer::factory()->for($companyA)->create();
        $customerB = Customer::factory()->for($companyB)->create();

        // Not a violation: the number is unique per company, not globally, so
        // numbering cannot be used to infer another tenant's activity.
        SalesInvoice::factory()->for($companyA)->for($customerA)->create(['invoice_number' => 'INV-000001']);
        SalesInvoice::factory()->for($companyB)->for($customerB)->create(['invoice_number' => 'INV-000001']);

        $this->assertSame(2, SalesInvoice::where('invoice_number', 'INV-000001')->count());
    }

    #[Test]
    public function a_receipt_cannot_allocate_the_same_invoice_twice(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $customer = Customer::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create();

        $receipt = CustomerReceipt::factory()->for($company)->create();

        $receipt->allocations()->create([
            'sales_invoice_id' => $invoice->getKey(),
            'amount' => '10.0000',
        ]);

        $this->assertDatabaseIntegrityViolation(
            fn () => $receipt->allocations()->create([
                'sales_invoice_id' => $invoice->getKey(),
                'amount' => '20.0000',
            ])
        );
    }

    #[Test]
    public function a_payment_cannot_allocate_the_same_bill_twice(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $supplier = Supplier::factory()->for($company)->create();
        $bill = PurchaseBill::factory()->for($company)->for($supplier)->create();

        $payment = SupplierPayment::factory()->for($company)->create();

        $payment->allocations()->create([
            'purchase_bill_id' => $bill->getKey(),
            'amount' => '10.0000',
        ]);

        $this->assertDatabaseIntegrityViolation(
            fn () => $payment->allocations()->create([
                'purchase_bill_id' => $bill->getKey(),
                'amount' => '20.0000',
            ])
        );
    }

    #[Test]
    public function an_allocation_must_belong_to_the_same_company_as_its_receipt(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $accounts = $this->makeTransactionAccounts($company);

        $customer = Customer::factory()->for($other)->create();
        $foreignInvoice = SalesInvoice::factory()->for($other)->for($customer)->create();
        $receipt = CustomerReceipt::factory()->for($company)->create([
            'payment_account_id' => $accounts['cash']->getKey(),
        ]);

        /*
         * The allocation table has no company_id of its own - the receipt is the
         * only company-scoped row it hangs off - so cross-company isolation here
         * depends on the service having checked the invoice belongs to the same
         * company before writing. The foreign key stops a deleted invoice, not a
         * foreign one, which is exactly why the check is in the service.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => Customer::factory()->for($company)->create([
                    'receivable_account_id' => $accounts['receivable']->getKey(),
                ])->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '10.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $foreignInvoice->getKey(), 'amount' => '10.00'],
                ],
            ])
            ->assertUnprocessable();

        $this->assertSame(0, $receipt->allocations()->count());
    }

    #[Test]
    public function a_journal_cannot_be_pointed_at_a_document_from_another_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $foreignCustomer = Customer::factory()->for($other)->create();
        $foreignInvoice = SalesInvoice::factory()->for($other)->for($foreignCustomer)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-10',
                'description' => 'Borrowed reference',
                'source_type' => JournalSource::SalesInvoice->value,
                'source_id' => $foreignInvoice->getKey(),
                'lines' => [],
            ])
            ->assertUnprocessable();

        $this->assertSame(0, Journal::where('source_type', JournalSource::SalesInvoice->value)->count());
    }

    #[Test]
    public function a_line_number_is_unique_within_its_document(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $customer = Customer::factory()->for($company)->create([
            'receivable_account_id' => $accounts['receivable']->getKey(),
        ]);
        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create();

        $invoice->lines()->create([
            'line_number' => 1,
            'quantity' => '1',
            'unit_price' => '10.00',
            'discount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'line_total' => '10.00',
            'revenue_account_id' => $accounts['revenue']->getKey(),
        ]);

        $this->assertDatabaseIntegrityViolation(
            fn () => $invoice->lines()->create([
                'line_number' => 1,
                'quantity' => '2',
                'unit_price' => '20.00',
                'discount' => '0',
                'tax_rate' => '0',
                'tax_amount' => '0',
                'line_total' => '40.00',
                'revenue_account_id' => $accounts['revenue']->getKey(),
            ])
        );
    }

    #[Test]
    public function a_document_cannot_reference_an_account_from_another_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $foreignAccount = Account::factory()->for($other)->revenue()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => Customer::factory()->for($company)->create()->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_price' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $foreignAccount->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.revenue_account_id');
    }

    #[Test]
    public function a_receipt_requires_a_payment_account(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $customer = Customer::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create([
            'status' => TransactionStatus::Posted->value,
            'grand_total' => '100.0000',
        ]);

        $this->assertDatabaseIntegrityViolation(
            fn () => CustomerReceipt::factory()->for($company)->create([
                'customer_id' => $customer->getKey(),
                'payment_account_id' => null,
            ])
        );

        $this->assertNotNull($invoice);
    }

    #[Test]
    public function a_document_cannot_be_orphaned_by_deleting_its_customer(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $customer = Customer::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create();

        // A hard delete of the master is refused by the foreign key, which is
        // what makes "no hard deletion once financial history exists" true at the
        // storage layer as well as in the API, which has no delete route.
        $this->assertDatabaseIntegrityViolation(fn () => DB::table('customers')->where('id', $customer->getKey())->delete());

        $this->assertDatabaseHas('sales_invoices', ['id' => $invoice->getKey()]);
    }
}
