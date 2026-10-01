<?php

namespace Tests\Feature\Reports;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 6 outstanding payables report.
 *
 * The mirror of receivables over purchase bills and supplier payments. The
 * "fully paid is excluded" and "future due date is current" properties are
 * asserted here too because they belong to the shared outstanding-balance scope,
 * and a bug that hid a partial bill would otherwise only show on one side.
 */
class PayablesReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_lists_outstanding_bills_with_paid_and_balance_due(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts, '2027-01-10', [
            'due_date' => '2027-02-10',
        ]);

        $this->postSupplierPayment($user, $company, $supplier, $accounts, '200.0000', [
            ['purchase_bill_id' => $bill->getKey(), 'amount' => '200.00'],
        ], '2027-01-20');

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/payables?as_of=2027-02-20')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('2027-02-20', $data['as_of']);
        $this->assertSame(1, $data['count']);
        $this->assertCount(1, $data['rows']);

        $row = $data['rows'][0];
        $this->assertSame($bill->getKey(), $row['bill_id']);
        $this->assertSame($supplier->getKey(), $row['supplier']['id']);
        $this->assertSame('500.0000', $row['grand_total']);
        $this->assertSame('200.0000', $row['paid_total']);
        $this->assertSame('300.0000', $row['balance_due']);
        $this->assertSame(10, $row['days_past_due']);

        $this->assertSame('300.0000', $data['totals']['balance_due']);
    }

    #[Test]
    public function it_excludes_fully_paid_bills(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->postSupplierPayment($user, $company, $supplier, $accounts, '500.0000', [
            ['purchase_bill_id' => $bill->getKey(), 'amount' => '500.00'],
        ], '2027-01-20');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/payables?as_of=2027-12-31')
            ->assertSuccessful()
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.rows', []);
    }

    #[Test]
    public function it_filters_by_supplier(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $first = $this->createSupplier($user, $company, $accounts, ['name' => 'First']);
        $second = $this->createSupplier($user, $company, $accounts, ['name' => 'Second']);

        $this->postBill($user, $company, $first, $accounts);
        $this->postBill($user, $company, $second, $accounts);

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/payables?supplier_id='.$first->getKey().'&as_of=2027-12-31')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame(1, $data['count']);
        $this->assertSame($first->getKey(), $data['rows'][0]['supplier']['id']);
    }

    #[Test]
    public function it_refuses_a_supplier_from_another_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $foreign = Supplier::factory()->for($this->createUnrelatedCompany())->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/payables?supplier_id='.$foreign->getKey())
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    #[Test]
    public function the_report_requires_the_reports_permission(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/payables')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/payables')
            ->assertSuccessful();
    }
}
