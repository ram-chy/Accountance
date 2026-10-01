<?php

namespace Tests\Feature\Reports;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 6 customer statement.
 *
 * The statement is derived from posted documents, not from the ledger: invoices
 * are debits and receipts are credits. These tests pin the two properties that
 * would be wrong if the statement re-derived itself from journal lines - a draft
 * has no entry and must not appear, and the running balance is receivable-positive
 * and equals the closing figure.
 *
 * Manual journals are intentionally absent from a counterparty statement (a
 * journal line has no customer_id), so no test asserts one here; that omission is
 * covered in the Phase 6 report.
 */
class CustomerStatementReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_lists_invoices_as_debits_and_receipts_as_credits_with_a_running_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts, '2027-01-10');

        $this->postCustomerReceipt($user, $company, $customer, $accounts, '80.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '80.00'],
        ], '2027-01-20');

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id='.$customer->getKey())
            ->assertSuccessful()
            ->json('data');

        $this->assertSame($customer->getKey(), $data['customer']['id']);
        $this->assertSame('0.0000', $data['opening_balance']);
        $this->assertCount(2, $data['rows']);

        $this->assertSame('invoice', $data['rows'][0]['type']);
        $this->assertSame('200.0000', $data['rows'][0]['debit']);
        $this->assertSame('0.0000', $data['rows'][0]['credit']);
        $this->assertSame('200.0000', $data['rows'][0]['running_balance']);

        $this->assertSame('receipt', $data['rows'][1]['type']);
        $this->assertSame('0.0000', $data['rows'][1]['debit']);
        $this->assertSame('80.0000', $data['rows'][1]['credit']);
        $this->assertSame('120.0000', $data['rows'][1]['running_balance']);

        $this->assertSame('200.0000', $data['totals']['debit']);
        $this->assertSame('80.0000', $data['totals']['credit']);
        $this->assertSame('120.0000', $data['closing_balance']);
    }

    #[Test]
    public function it_excludes_draft_invoices_and_draft_receipts(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $posted = $this->postInvoice($user, $company, $customer, $accounts, '2027-01-10');

        // A second, deliberately unposted invoice.
        $this->createDraftInvoice($user, $company, $customer, $accounts);

        // A receipt that is created against the posted invoice but never posted:
        // it has no journal, so it must not credit the statement.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '50.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $posted->getKey(), 'amount' => '50.00'],
                ],
            ])
            ->assertSuccessful();

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id='.$customer->getKey())
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(1, $data['rows']);
        $this->assertSame('invoice', $data['rows'][0]['type']);
        $this->assertSame('200.0000', $data['rows'][0]['running_balance']);
        $this->assertSame('200.0000', $data['closing_balance']);
    }

    #[Test]
    public function it_carries_an_opening_balance_from_before_the_window(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts, '2027-01-10');

        $this->postCustomerReceipt($user, $company, $customer, $accounts, '50.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '50.00'],
        ], '2027-02-10');

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id='.$customer->getKey().'&from_date=2027-02-01&to_date=2027-02-28')
            ->assertSuccessful()
            ->json('data');

        // The January invoice is before the window, so it opens the statement
        // rather than appearing as a row.
        $this->assertSame('200.0000', $data['opening_balance']);
        $this->assertCount(1, $data['rows']);
        $this->assertSame('receipt', $data['rows'][0]['type']);
        $this->assertSame('150.0000', $data['rows'][0]['running_balance']);
        $this->assertSame('150.0000', $data['closing_balance']);
    }

    #[Test]
    public function it_requires_a_customer_id(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement')
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    #[Test]
    public function it_refuses_a_customer_from_another_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $stranger = $this->createUnrelatedCompany();
        $foreign = Customer::factory()->for($stranger)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id='.$foreign->getKey())
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    #[Test]
    public function a_backwards_date_window_is_rejected(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id='.$customer->getKey().'&from_date=2027-03-01&to_date=2027-01-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.to_date.0', 'The to date must be on or after the from date.');
    }

    #[Test]
    public function the_report_requires_the_reports_permission(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id=1')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        // With the permission but no matching customer the request reaches the
        // controller and fails validation, proving authorization no longer blocks
        // it (a 403 would mean the permission grant did not take).
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/customer-statement?customer_id=1')
            ->assertStatus(422);
    }
}
