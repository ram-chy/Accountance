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
 * The Phase 6 aged receivables and payables reports.
 *
 * The aging report is the outstanding document set grouped into config-defined
 * buckets, so the tests fix the exact bucket boundaries at their edges: an
 * invoice 5 days overdue lands in "current", 54 days in 31-60, and 104 days in
 * 91-120. A negative age (not yet due) is deliberately checked on the
 * receivables report instead; here the concern is the boundary arithmetic and
 * that the grouped total equals the ungrouped report.
 */
class AgingReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_buckets_receivables_by_days_past_due(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $alpha = $this->createCustomer($user, $company, $accounts, ['name' => 'Alpha']);
        $beta = $this->createCustomer($user, $company, $accounts, ['name' => 'Beta']);

        // Current, 31-60 and 91-120 respectively, as of 2027-04-15.
        $this->postInvoice($user, $company, $alpha, $accounts, '2027-03-01', ['due_date' => '2027-04-10']);
        $this->postInvoice($user, $company, $alpha, $accounts, '2027-01-01', ['due_date' => '2027-02-20']);
        $this->postInvoice($user, $company, $beta, $accounts, '2026-12-01', ['due_date' => '2027-01-01']);

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables-aging?as_of=2027-04-15')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('2027-04-15', $data['as_of']);

        $byBucket = collect($data['buckets'])->keyBy('key');
        $this->assertSame('200.0000', $byBucket['current']['total']);
        $this->assertSame('200.0000', $byBucket['days_31_60']['total']);
        $this->assertSame('0.0000', $byBucket['days_61_90']['total']);
        $this->assertSame('200.0000', $byBucket['days_91_120']['total']);
        $this->assertSame('0.0000', $byBucket['days_121_plus']['total']);

        $this->assertSame('200.0000', $data['totals']['by_bucket']['current']);
        $this->assertSame('200.0000', $data['totals']['by_bucket']['days_91_120']);
        $this->assertSame('600.0000', $data['totals']['total']);

        // Rows are grouped by counterparty, sorted by name.
        $this->assertCount(2, $data['rows']);
        $this->assertSame($alpha->getKey(), $data['rows'][0]['counterparty']['id']);
        $this->assertSame('400.0000', $data['rows'][0]['total']);
        $this->assertSame('200.0000', $data['rows'][0]['buckets']['current']);
        $this->assertSame('200.0000', $data['rows'][0]['buckets']['days_31_60']);

        $this->assertSame($beta->getKey(), $data['rows'][1]['counterparty']['id']);
        $this->assertSame('200.0000', $data['rows'][1]['total']);
        $this->assertSame('200.0000', $data['rows'][1]['buckets']['days_91_120']);
    }

    #[Test]
    public function it_buckets_payables_by_days_past_due(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $alpha = $this->createSupplier($user, $company, $accounts, ['name' => 'Alpha']);

        $this->postBill($user, $company, $alpha, $accounts, '2027-03-01', ['due_date' => '2027-04-10']);
        $this->postBill($user, $company, $alpha, $accounts, '2027-01-01', ['due_date' => '2027-02-20']);

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/payables-aging?as_of=2027-04-15')
            ->assertSuccessful()
            ->json('data');

        $byBucket = collect($data['buckets'])->keyBy('key');
        $this->assertSame('500.0000', $byBucket['current']['total']);
        $this->assertSame('500.0000', $byBucket['days_31_60']['total']);
        $this->assertSame('1000.0000', $data['totals']['total']);

        $this->assertCount(1, $data['rows']);
        $this->assertSame('1000.0000', $data['rows'][0]['total']);
    }

    #[Test]
    public function the_aging_total_matches_the_receivables_report(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts, '2027-01-01', ['due_date' => '2027-02-20']);
        $this->postCustomerReceipt($user, $company, $customer, $accounts, '50.0000', [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '50.00'],
        ], '2027-01-20');

        $balanceDue = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables?as_of=2027-04-15')
            ->json('data.totals.balance_due');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables-aging?as_of=2027-04-15')
            ->assertSuccessful()
            ->assertJsonPath('data.totals.total', $balanceDue);
    }

    #[Test]
    public function it_refuses_a_customer_from_another_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $foreign = Customer::factory()->for($this->createUnrelatedCompany())->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables-aging?customer_id='.$foreign->getKey())
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
            ->getJson('/api/accounting/reports/receivables-aging')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/receivables-aging')
            ->assertSuccessful();
    }
}
