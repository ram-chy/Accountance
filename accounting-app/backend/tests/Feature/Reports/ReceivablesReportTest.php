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
 * The Phase 6 outstanding receivables report.
 *
 * The report is driven by Phase 5's own `withOutstandingBalance` scope and
 * SettlementService figures, so these tests assert the reported numbers rather
 * than re-deriving them: a partially paid invoice shows its full grand total,
 * the paid part, and the remainder, and a fully paid invoice disappears.
 */
class ReceivablesReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_lists_outstanding_invoices_with_paid_and_balance_due(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts, '2027-01-10', [
            'due_date' => '2027-02-10',
        ]);

        $this->postCustomerReceipt($user, $company, $customer, $accounts, '50.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '50.00'],
        ], '2027-01-20');

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables?as_of=2027-02-20')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('2027-02-20', $data['as_of']);
        $this->assertSame(1, $data['count']);
        $this->assertCount(1, $data['rows']);

        $row = $data['rows'][0];
        $this->assertSame($invoice->getKey(), $row['invoice_id']);
        $this->assertSame($customer->getKey(), $row['customer']['id']);
        $this->assertSame('200.0000', $row['grand_total']);
        $this->assertSame('50.0000', $row['paid_total']);
        $this->assertSame('150.0000', $row['balance_due']);
        $this->assertSame(10, $row['days_past_due']);

        $this->assertSame('200.0000', $data['totals']['grand_total']);
        $this->assertSame('50.0000', $data['totals']['paid_total']);
        $this->assertSame('150.0000', $data['totals']['balance_due']);
    }

    #[Test]
    public function it_excludes_fully_paid_invoices(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->postCustomerReceipt($user, $company, $customer, $accounts, '200.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
        ], '2027-01-20');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables?as_of=2027-12-31')
            ->assertSuccessful()
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.rows', [])
            ->assertJsonPath('data.totals.balance_due', '0.0000');
    }

    #[Test]
    public function an_invoice_not_yet_due_is_current(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->postInvoice($user, $company, $customer, $accounts, '2027-01-10', [
            'due_date' => '2027-03-10',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables?as_of=2027-02-01')
            ->assertSuccessful()
            ->assertJsonPath('data.rows.0.days_past_due', 0);
    }

    #[Test]
    public function it_filters_by_customer(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $first = $this->createCustomer($user, $company, $accounts, ['name' => 'First']);
        $second = $this->createCustomer($user, $company, $accounts, ['name' => 'Second']);

        $this->postInvoice($user, $company, $first, $accounts);
        $this->postInvoice($user, $company, $second, $accounts);

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables?customer_id='.$first->getKey().'&as_of=2027-12-31')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame(1, $data['count']);
        $this->assertSame($first->getKey(), $data['rows'][0]['customer']['id']);
    }

    #[Test]
    public function it_refuses_a_customer_from_another_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $foreign = Customer::factory()->for($this->createUnrelatedCompany())->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables?customer_id='.$foreign->getKey())
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    #[Test]
    public function the_report_requires_the_reports_permission(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables')
            ->assertSuccessful();
    }
}
