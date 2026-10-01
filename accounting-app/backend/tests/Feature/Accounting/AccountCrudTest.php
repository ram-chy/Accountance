<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountCrudTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    private function admin(): User
    {
        return $this->createUserWithRole(RoleName::Admin);
    }

    #[Test]
    public function an_accountant_can_create_an_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounts', [
                'code' => '1000',
                'name' => 'Cash',
                'account_type' => AccountType::Asset->value,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', '1000')
            ->assertJsonPath('data.name', 'Cash')
            ->assertJsonPath('data.account_type', 'ASSET')
            // Derived from the type, not stored by the client.
            ->assertJsonPath('data.normal_balance', 'DEBIT')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_system', false);

        $this->assertDatabaseHas('accounts', [
            'company_id' => $company->getKey(),
            'code' => '1000',
            'name' => 'Cash',
        ]);
    }

    #[Test]
    public function a_new_account_is_always_active_and_not_system(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounts', [
                'code' => '1000',
                'name' => 'Cash',
                'account_type' => AccountType::Asset->value,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_system', false);
    }

    /**
     * is_active and is_system are not in the model's fillable array, so a client
     * cannot create a pre-deactivated account or claim ownership of a
     * system-controlled one by including them in the request body.
     */
    #[Test]
    public function a_client_cannot_mass_assign_lifecycle_flags_on_create(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounts', [
                'code' => '1000',
                'name' => 'Cash',
                'account_type' => AccountType::Asset->value,
                'is_active' => false,
                'is_system' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_system', false);
    }

    #[Test]
    public function account_codes_are_unique_within_a_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        Account::factory()->for($company)->create(['code' => '1000', 'name' => 'Existing']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounts', [
                'code' => '1000',
                'name' => 'Duplicate',
                'account_type' => AccountType::Asset->value,
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    /**
     * The spec's explicit requirement: two different companies may use the same
     * code. Uniqueness is per company, never global.
     */
    #[Test]
    public function two_companies_may_use_the_same_account_code(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        Account::factory()->for($companyA)->create([
            'code' => '1000',
            'name' => 'Cash A',
            'account_type' => AccountType::Asset->value,
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->postJson('/api/accounts', [
                'code' => '1000',
                'name' => 'Cash B',
                'account_type' => AccountType::Asset->value,
            ]);

        $response->assertCreated();

        // Both rows exist - that is the point of per-company uniqueness. Each
        // company holds exactly one, and the global count of 2 is proof the
        // unique index is (company_id, code) rather than (code) alone.
        $this->assertSame(1, Account::query()->where('company_id', $companyA->getKey())->where('code', '1000')->count());
        $this->assertSame(1, Account::query()->where('company_id', $companyB->getKey())->where('code', '1000')->count());
        $this->assertSame(2, Account::query()->where('code', '1000')->count());
    }

    #[Test]
    public function account_names_are_unique_within_a_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        Account::factory()->for($company)->create([
            'code' => '1000',
            'name' => 'Cash',
            'account_type' => AccountType::Asset->value,
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounts', [
                'code' => '1010',
                'name' => 'Cash',
                'account_type' => AccountType::Asset->value,
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    #[Test]
    public function the_database_rejects_a_duplicate_account_code(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        Account::factory()->for($company)->create(['code' => '1000', 'name' => 'One']);

        // Bypass the API entirely: the unique index must hold on its own.
        $this->expectException(QueryException::class);

        Account::factory()->for($company)->create(['code' => '1000', 'name' => 'Two']);
    }

    #[Test]
    public function only_the_five_fundamental_account_types_are_accepted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounts', [
                'code' => '1000',
                'name' => 'Invalid Type',
                'account_type' => 'CONTRA_ASSET',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('account_type');
    }

    #[Test]
    public function the_account_types_endpoint_exposes_normal_balances(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounts/types');

        $response->assertSuccessful()->assertJsonCount(5, 'data');

        $map = collect($response->json('data'))->pluck('normal_balance', 'value');

        $this->assertSame('DEBIT', $map['ASSET']);
        $this->assertSame('DEBIT', $map['EXPENSE']);
        $this->assertSame('CREDIT', $map['LIABILITY']);
        $this->assertSame('CREDIT', $map['EQUITY']);
        $this->assertSame('CREDIT', $map['REVENUE']);
    }

    #[Test]
    public function accounts_can_be_updated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->asset()->create([
            'code' => '1000',
            'name' => 'Cash',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounts/{$account->getKey()}", ['name' => 'Cash on Hand']);

        $response->assertSuccessful()->assertJsonPath('data.name', 'Cash on Hand');

        $this->assertDatabaseHas('accounts', [
            'id' => $account->getKey(),
            'name' => 'Cash on Hand',
        ]);
    }

    /**
     * A partial update must not collide with the account's own existing code.
     */
    #[Test]
    public function updating_only_the_name_leaves_the_code_valid(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->create([
            'code' => '1000',
            'name' => 'Cash',
            'account_type' => AccountType::Asset->value,
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounts/{$account->getKey()}", ['name' => 'Cash Renamed'])
            ->assertSuccessful()
            ->assertJsonPath('data.code', '1000');
    }

    #[Test]
    public function accounts_can_be_deactivated_and_reactivated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->create([
            'code' => '1000',
            'name' => 'Cash',
            'account_type' => AccountType::Asset->value,
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounts/{$account->getKey()}/deactivate")
            ->assertSuccessful()
            ->assertJsonPath('data.is_active', false);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounts/{$account->getKey()}/activate")
            ->assertSuccessful()
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function an_account_without_history_can_be_deleted(): void
    {
        // Admin, because the Accountant role deliberately lacks accounts.delete.
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->create([
            'code' => '1000',
            'name' => 'Unused',
            'account_type' => AccountType::Asset->value,
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/accounts/{$account->getKey()}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('accounts', ['id' => $account->getKey()]);
    }

    #[Test]
    public function deactivated_accounts_are_still_listed_by_default(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        Account::factory()->for($company)->create(['code' => '1000', 'name' => 'Active']);
        Account::factory()->for($company)->inactive()->create(['code' => '1010', 'name' => 'Retired']);

        // Historical reports need deactivated accounts, so the default listing
        // includes them and ?is_active=true narrows it.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounts')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounts?is_active=1')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '1000');
    }

    #[Test]
    public function the_listing_can_be_filtered_by_account_type(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        Account::factory()->for($company)->asset()->create(['code' => '1000', 'name' => 'Cash']);
        Account::factory()->for($company)->revenue()->create(['code' => '4000', 'name' => 'Sales']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounts?account_type=REVENUE')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account_type', 'REVENUE');
    }

    #[Test]
    public function the_listing_reports_whether_an_account_has_history(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        $unused = Account::factory()->for($company)->expense()->create([
            'code' => '5000',
            'name' => 'Office Supplies',
        ]);

        // Both sides of the journal acquire history; the third account does not,
        // so the flag is verified as discriminating rather than always-true.
        $this->postJournal($user, $company, $cash, $revenue);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounts');

        $response->assertSuccessful();

        $flags = collect($response->json('data'))
            ->pluck('has_journal_history', 'code');

        $this->assertTrue($flags['1000'], 'Cash was debited and should report history.');
        $this->assertTrue($flags['4000'], 'Revenue was credited and should report history.');
        $this->assertFalse($flags['5000'], 'An unused account must not report history.');
    }
}
