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
 * The Phase 6 supplier statement.
 *
 * The mirror of the customer statement with the sides swapped: bills are
 * credits, payments are debits, and the running balance is payable-positive
 * (positive means we owe the supplier). The dedicated test that the closing
 * figure agrees with the running balance exists because a naive
 * "debits minus credits" closing would report a payable as a negative number.
 */
class SupplierStatementReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_lists_bills_as_credits_and_payments_as_debits_with_a_running_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts, '2027-01-10');

        $this->postSupplierPayment($user, $company, $supplier, $accounts, '200.0000', [
            ['purchase_bill_id' => $bill->getKey(), 'amount' => '200.00'],
        ], '2027-01-20');

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/supplier-statement?supplier_id='.$supplier->getKey())
            ->assertSuccessful()
            ->json('data');

        $this->assertSame($supplier->getKey(), $data['supplier']['id']);
        $this->assertSame('0.0000', $data['opening_balance']);
        $this->assertCount(2, $data['rows']);

        $this->assertSame('bill', $data['rows'][0]['type']);
        $this->assertSame('0.0000', $data['rows'][0]['debit']);
        $this->assertSame('500.0000', $data['rows'][0]['credit']);
        $this->assertSame('500.0000', $data['rows'][0]['running_balance']);

        $this->assertSame('payment', $data['rows'][1]['type']);
        $this->assertSame('200.0000', $data['rows'][1]['debit']);
        $this->assertSame('0.0000', $data['rows'][1]['credit']);
        $this->assertSame('300.0000', $data['rows'][1]['running_balance']);

        $this->assertSame('200.0000', $data['totals']['debit']);
        $this->assertSame('500.0000', $data['totals']['credit']);

        // Payable-positive: the closing must match the last running balance, not
        // the debit-minus-credit difference (which would be -300).
        $this->assertSame('300.0000', $data['closing_balance']);
    }

    #[Test]
    public function it_carries_an_opening_balance_from_before_the_window(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts, '2027-01-10');

        $this->postSupplierPayment($user, $company, $supplier, $accounts, '100.0000', [
            ['purchase_bill_id' => $bill->getKey(), 'amount' => '100.00'],
        ], '2027-02-10');

        $data = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/supplier-statement?supplier_id='.$supplier->getKey().'&from_date=2027-02-01&to_date=2027-02-28')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('500.0000', $data['opening_balance']);
        $this->assertCount(1, $data['rows']);
        $this->assertSame('payment', $data['rows'][0]['type']);
        $this->assertSame('400.0000', $data['rows'][0]['running_balance']);
        $this->assertSame('400.0000', $data['closing_balance']);
    }

    #[Test]
    public function it_requires_a_supplier_id(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/supplier-statement')
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    #[Test]
    public function it_refuses_a_supplier_from_another_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $stranger = $this->createUnrelatedCompany();
        $foreign = Supplier::factory()->for($stranger)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/supplier-statement?supplier_id='.$foreign->getKey())
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
            ->getJson('/api/accounting/reports/supplier-statement?supplier_id=1')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/supplier-statement?supplier_id=1')
            ->assertStatus(422);
    }
}
