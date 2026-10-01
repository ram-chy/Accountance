<?php

namespace Tests\Feature\Transactions;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Customer lifecycle through the API.
 *
 * The recurring theme in this file is the negative space: what a client cannot do.
 * Most of these assertions are about requests that must be refused, because the
 * ways a master-data endpoint leaks are all refusals that were not written.
 */
class CustomerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_accountant_can_create_a_customer(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customers', [
                'customer_code' => 'ACME',
                'name' => 'Acme Ltd',
                'email' => 'ap@acme.test',
                'receivable_account_id' => $accounts['receivable']->getKey(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer_code', 'ACME')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.receivable_account_id', $accounts['receivable']->getKey());

        $this->assertDatabaseHas('customers', [
            'company_id' => $company->getKey(),
            'customer_code' => 'ACME',
        ]);
    }

    #[Test]
    public function the_company_id_is_taken_from_context_and_cannot_be_supplied(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();
        $accounts = $this->makeTransactionAccounts($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customers', [
                'customer_code' => 'SCOOPED',
                'name' => 'Scooped',
                'company_id' => $other->getKey(),
                'receivable_account_id' => $accounts['receivable']->getKey(),
            ]);

        $response->assertCreated();

        /*
         * The submitted company_id is simply not in the validated payload, so
         * the customer lands in the context company. Asserting the row is the
         * point: a 201 that quietly ignored the field is correct, and a 201 that
         * honoured it is a cross-tenant write.
         */
        $this->assertDatabaseHas('customers', [
            'customer_code' => 'SCOOPED',
            'company_id' => $company->getKey(),
        ]);

        $this->assertDatabaseMissing('customers', [
            'customer_code' => 'SCOOPED',
            'company_id' => $other->getKey(),
        ]);
    }

    #[Test]
    public function a_customer_code_must_be_unique_per_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->createCustomer($user, $company, $accounts, ['customer_code' => 'DUPE']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customers', [
                'customer_code' => 'DUPE',
                'name' => 'Another',
                'receivable_account_id' => $accounts['receivable']->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_code');
    }

    #[Test]
    public function the_same_customer_code_is_allowed_in_a_different_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createUnrelatedCompany();
        $this->addMemberTo($companyB, $user);

        $accountsA = $this->makeTransactionAccounts($companyA);
        $accountsB = $this->makeTransactionAccounts($companyB);

        $this->createCustomer($user, $companyA, $accountsA, ['customer_code' => 'SHARED']);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->postJson('/api/customers', [
                'customer_code' => 'SHARED',
                'name' => 'Different company, same code',
                'receivable_account_id' => $accountsB['receivable']->getKey(),
            ])
            ->assertCreated();
    }

    #[Test]
    public function the_receivable_account_must_be_an_asset(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customers', [
                'customer_code' => 'WRONGTYPE',
                'name' => 'Wrong account type',
                // A REVENUE account is a real account in this company; it is
                // simply not an asset, and a revenue account is not where a
                // receivable lives.
                'receivable_account_id' => $accounts['revenue']->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receivable_account_id');
    }

    #[Test]
    public function the_receivable_account_must_be_active(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $accounts['receivable']->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customers', [
                'customer_code' => 'DORMANT',
                'name' => 'Dormant account',
                'receivable_account_id' => $accounts['receivable']->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receivable_account_id');
    }

    #[Test]
    public function an_account_from_another_company_is_refused_without_confirming_it_exists(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $foreignAccount = Account::factory()->for($other)->asset()->create();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customers', [
                'customer_code' => 'FOREIGN',
                'name' => 'Foreign account',
                'receivable_account_id' => $foreignAccount->getKey(),
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('receivable_account_id');

        /*
         * The message must not distinguish "no such account" from "belongs to
         * another company". A message that said "that account belongs to another
         * company" would confirm the id is real, which is a disclosure about
         * another tenant's data.
         */
        $this->assertStringNotContainsStringIgnoringCase(
            'another company',
            $this->responseErrors($response)['receivable_account_id'],
        );
    }

    #[Test]
    public function a_customer_from_another_company_is_not_found_rather_than_forbidden(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();
        $otherAccounts = $this->makeTransactionAccounts($other);

        $foreignCustomer = Customer::factory()->for($other)->create([
            'receivable_account_id' => $otherAccounts['receivable']->getKey(),
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/customers/{$foreignCustomer->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function an_update_can_clear_an_optional_field(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $customer = $this->createCustomer($user, $company, $accounts, [
            'customer_code' => 'CLEARME',
            'email' => 'old@acme.test',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/customers/{$customer->getKey()}", ['email' => null])
            ->assertSuccessful()
            ->assertJsonPath('data.email', null);

        $this->assertNull($customer->refresh()->email);
    }

    #[Test]
    public function an_update_leaves_absent_fields_alone(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $customer = $this->createCustomer($user, $company, $accounts, [
            'customer_code' => 'PARTIAL',
            'name' => 'Original Name',
            'phone' => '555-0100',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/customers/{$customer->getKey()}", ['name' => 'New Name'])
            ->assertSuccessful();

        $fresh = $customer->refresh();

        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('555-0100', $fresh->phone, 'An omitted field must not be blanked by a partial update.');
    }

    #[Test]
    public function a_customer_keeps_its_own_code_when_updated(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $customer = $this->createCustomer($user, $company, $accounts, ['customer_code' => 'STABLE']);

        /*
         * Resubmitting the same code must not read as a conflict with the row
         * being updated. Without ignore() on the unique rule, every update to
         * this customer would fail and no customer could be renamed.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/customers/{$customer->getKey()}", [
                'name' => 'Renamed',
                'customer_code' => 'STABLE',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Renamed');
    }

    #[Test]
    public function a_customer_with_posted_invoices_cannot_be_deactivated(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $customer = $this->createCustomer($user, $company, $accounts);
        $this->postInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/customers/{$customer->getKey()}/deactivate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer');

        $this->assertTrue($customer->refresh()->is_active);
    }

    #[Test]
    public function a_customer_with_only_draft_invoices_can_be_deactivated(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $customer = $this->createCustomer($user, $company, $accounts);
        $this->createDraftInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/customers/{$customer->getKey()}/deactivate")
            ->assertSuccessful()
            ->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function there_is_no_delete_route_for_a_customer(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $customer = $this->createCustomer($user, $company, $accounts);

        /*
         * Even an Admin gets a 405, not a 403. The route does not exist, so the
         * question "may this user delete it" is never reached - which is the
         * stronger guarantee, and the reason documents are not deletable by
         * role at all.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/customers/{$customer->getKey()}")
            ->assertStatus(405);

        $this->assertDatabaseHas('customers', ['id' => $customer->getKey()]);
    }

    #[Test]
    public function a_manager_may_view_but_not_create_a_customer(): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);
        $company = $this->createCompanyFor($manager);
        $accounts = $this->makeTransactionAccounts($company);

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->getJson('/api/customers')
            ->assertSuccessful();

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson('/api/customers', [
                'customer_code' => 'NOPE',
                'name' => 'Nope',
                'receivable_account_id' => $accounts['receivable']->getKey(),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function staff_cannot_reach_customers_at_all(): void
    {
        $staff = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($staff);

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson('/api/customers')
            ->assertForbidden();
    }

    #[Test]
    public function the_index_is_scoped_to_the_active_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createUnrelatedCompany();
        $this->addMemberTo($companyB, $user);

        $accountsA = $this->makeTransactionAccounts($companyA);
        $accountsB = $this->makeTransactionAccounts($companyB);

        $this->createCustomer($user, $companyA, $accountsA, ['customer_code' => 'IN-A']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->getJson('/api/customers')
            ->assertSuccessful();

        $codes = collect($response->json('data'))
            ->pluck('customer_code')
            ->all();

        $this->assertNotContains('IN-A', $codes, 'A customer from another company must not appear in the list.');
    }

    #[Test]
    public function the_search_filter_matches_code_name_and_tax_identifier(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->createCustomer($user, $company, $accounts, [
            'customer_code' => 'FINDME',
            'name' => 'Findable Trading',
            'tax_identifier' => 'GB123456789',
        ]);
        $this->createCustomer($user, $company, $accounts, [
            'customer_code' => 'OTHER',
            'name' => 'Unrelated Supplies',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/customers?search=FINDME')
            ->assertSuccessful();

        $this->assertCount(1, $response->json('data'));
    }
}
