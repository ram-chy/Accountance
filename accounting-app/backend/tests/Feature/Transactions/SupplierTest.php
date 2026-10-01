<?php

namespace Tests\Feature\Transactions;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suppliers, the purchasing-side mirror of CustomerTest.
 *
 * The two master records differ in exactly one accounting respect: a customer
 * owes the company money and a supplier is owed money, so the receivable account
 * must be an asset and the payable account a liability. Every other rule -
 * codes, deactivation, company scoping, the posted-document guard - is
 * deliberately identical, which is why this file asserts the same behaviours
 * rather than only the differences.
 */
class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function a_supplier_is_created_with_a_payable_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/suppliers', [
                'supplier_code' => 'ACME',
                'name' => 'Acme Supplies',
                'email' => 'ap@acme.test',
                'payable_account_id' => $accounts['payable']->getKey(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.supplier_code', 'ACME')
            ->assertJsonPath('data.name', 'Acme Supplies')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.payable_account_id', $accounts['payable']->getKey());
    }

    #[Test]
    public function the_payable_account_must_be_a_liability(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/suppliers', [
                'supplier_code' => 'WRONGTYPE',
                'name' => 'Wrong payable type',
                'payable_account_id' => $accounts['cash']->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payable_account_id');

        $this->assertSame(0, Supplier::count());
    }

    #[Test]
    public function an_inactive_payable_account_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $accounts['payable']->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/suppliers', [
                'supplier_code' => 'INACTIVE',
                'name' => 'Inactive payable',
                'payable_account_id' => $accounts['payable']->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payable_account_id');
    }

    #[Test]
    public function a_payable_account_from_another_company_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $foreignAccount = Account::factory()->for($other)->liability()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/suppliers', [
                'supplier_code' => 'CROSSCO',
                'name' => 'Cross tenant',
                'payable_account_id' => $foreignAccount->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payable_account_id');
    }

    #[Test]
    public function a_duplicate_supplier_code_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->createSupplier($user, $company, $accounts, ['supplier_code' => 'DUP']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/suppliers', [
                'supplier_code' => 'DUP',
                'name' => 'Second one',
                'payable_account_id' => $accounts['payable']->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_code');
    }

    #[Test]
    public function the_same_code_may_exist_in_two_companies(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $accountsA = $this->makeTransactionAccounts($companyA);
        // A second company the same user belongs to, because the isolation being
        // tested is the unique index, not membership.
        $companyB = $this->createCompanyFor($user, [], isDefault: false);
        $accountsB = $this->makeTransactionAccounts($companyB);

        $this->createSupplier($user, $companyA, $accountsA, ['supplier_code' => 'SHARED']);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->postJson('/api/suppliers', [
                'supplier_code' => 'SHARED',
                'name' => 'Different company',
                'payable_account_id' => $accountsB['payable']->getKey(),
            ])
            ->assertCreated();
    }

    #[Test]
    public function a_supplier_can_be_updated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts, ['supplier_code' => 'OLD', 'name' => 'Old Name']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/suppliers/{$supplier->getKey()}", ['name' => 'New Name'])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'New Name')
            // Absent fields are left alone rather than blanked.
            ->assertJsonPath('data.supplier_code', 'OLD');
    }

    #[Test]
    public function nullable_fields_can_be_cleared_on_update(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts, [
            'supplier_code' => 'CLEAR',
            'email' => 'ap@acme.test',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/suppliers/{$supplier->getKey()}", ['email' => null])
            ->assertSuccessful()
            ->assertJsonPath('data.email', null);

        $this->assertNull(Supplier::findOrFail($supplier->getKey())->email);
    }

    #[Test]
    public function a_supplier_can_be_deactivated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/suppliers/{$supplier->getKey()}/deactivate")
            ->assertSuccessful()
            ->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function a_deactivated_supplier_cannot_be_billed(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/suppliers/{$supplier->getKey()}/deactivate")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');
    }

    #[Test]
    public function a_supplier_from_another_company_is_not_found(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $foreign = Supplier::factory()->for($other)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/suppliers/{$foreign->getKey()}")
            ->assertNotFound();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/suppliers/{$foreign->getKey()}", ['name' => 'Tampered'])
            ->assertNotFound();
    }

    #[Test]
    public function the_supplier_list_is_scoped_to_the_active_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $mine = Supplier::factory()->for($company)->create();
        Supplier::factory()->for($other)->create();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/suppliers')
            ->assertSuccessful();

        $this->assertSame([$mine->getKey()], array_column($response->json('data'), 'id'));
    }

    #[Test]
    public function a_supplier_cannot_be_hard_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])->assertCreated();

        /*
         * There is no delete route for a supplier, and deactivation is the only
         * way to retire one. A supplier with bills attached is referenced by a
         * financial document, and removing the master would leave that document
         * pointing at nothing - so history stays readable and the supplier stays
         * on the record as inactive.
         *
         * 405 rather than 404: the route does not exist at all, which the router
         * says by refusing the method on a known path.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/suppliers/{$supplier->getKey()}")
            ->assertStatus(405);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->getKey()]);
        $this->assertDatabaseHas('purchase_bills', ['id' => $bill->json('data.id')]);
    }
}
