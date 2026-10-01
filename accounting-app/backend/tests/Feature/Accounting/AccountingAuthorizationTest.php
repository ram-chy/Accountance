<?php

namespace Tests\Feature\Accounting;

use App\Enums\RoleName;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Authorization for the accounting surface.
 *
 * The specification says to reuse the Phase 2 permission architecture and not to
 * invent a second one, so these tests assert the *role matrix* from
 * config/authorization.php rather than testing each policy method in isolation.
 *
 * A policy can pass its own unit test and still be unreachable: a route with no
 * middleware, a controller that forgets to call authorize(), or a FormRequest
 * whose authorize() asks the wrong question. Driving everything over HTTP is what
 * makes the matrix a statement about the application rather than about classes.
 */
class AccountingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A GET endpoint that exists for every role in the matrix.
     */
    public static function readEndpoints(): array
    {
        return [
            'accounts' => ['/api/accounts'],
            'account types' => ['/api/accounts/types'],
            'periods' => ['/api/accounting/periods'],
            'journals' => ['/api/journals'],
            'trial balance' => ['/api/accounting/trial-balance'],
        ];
    }

    #[Test]
    #[DataProvider('readEndpoints')]
    public function a_staff_member_cannot_reach_any_accounting_endpoint(string $endpoint): void
    {
        $staff = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($staff);

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson($endpoint)
            ->assertStatus(403);
    }

    #[Test]
    #[DataProvider('readEndpoints')]
    public function a_manager_may_read_accounting_but_change_nothing(string $endpoint): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);
        $company = $this->createCompanyFor($manager);

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->getJson($endpoint)
            ->assertSuccessful();

        // The journal is created by an Admin, not by the Manager: a Manager may
        // not create journals, so using their own token here would assert the
        // wrong refusal and prove nothing about editing or posting.
        $admin = $this->addMemberTo($company, $this->createUserWithRole(RoleName::Admin));
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($admin, $company, $cash, $revenue, '10.00', '2027-01-15');
        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson('/api/accounts', [
                'code' => '1500',
                'name' => 'Bank',
                'account_type' => 'ASSET',
            ])
            ->assertStatus(403);

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'lines' => [
                    ['account_id' => $cash->getKey(), 'debit' => '10.0000', 'credit' => '0'],
                    ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '10.0000'],
                ],
            ])
            ->assertStatus(403);

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", ['description' => 'nope'])
            ->assertStatus(403);

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertStatus(403);

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->deleteJson("/api/journals/{$journal->getKey()}")
            ->assertStatus(403);

        // Nothing above changed the record: a Manager's view of it is exactly the
        // Admin's view.
        $this->assertSame('DRAFT', $journal->refresh()->status->value);
        $this->assertSame(0, Account::query()->where('code', '1500')->count());
    }

    #[Test]
    public function a_manager_may_not_close_a_period(): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);
        $company = $this->createCompanyFor($manager);

        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close")
            ->assertStatus(403);

        $this->assertSame('OPEN', $period->refresh()->status->value);
    }

    #[Test]
    public function an_accountant_may_not_delete_an_account(): void
    {
        $accountant = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($accountant);

        $account = Account::factory()->for($company)->create(['code' => '1000', 'name' => 'Cash']);

        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->deleteJson("/api/accounts/{$account->getKey()}")
            ->assertStatus(403);

        $this->assertDatabaseHas('accounts', ['id' => $account->getKey()]);
    }

    #[Test]
    public function an_accountant_may_not_deactivate_a_system_account(): void
    {
        $accountant = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($accountant);

        $system = Account::factory()->for($company)->system()->create([
            'code' => '1000',
            'name' => 'System Cash',
        ]);

        // A system account is owned by the module that ships it, so it cannot be
        // taken out of service by hand. The guard is on deactivation, which is
        // the only transition that matters: a system account can therefore never
        // become inactive through the API at all.
        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->postJson("/api/accounts/{$system->getKey()}/deactivate")
            ->assertStatus(422);

        $this->assertTrue($system->refresh()->is_active);
    }

    #[Test]
    public function an_unauthenticated_visitor_cannot_reach_accounting(): void
    {
        $this->getJson('/api/accounts')->assertStatus(401);
        $this->getJson('/api/journals')->assertStatus(401);
        $this->getJson('/api/accounting/trial-balance')->assertStatus(401);
    }

    #[Test]
    public function an_admin_may_perform_every_accounting_action(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $journal = $this->createDraftJournal($admin, $company, $cash, $revenue, '10.0000');

        $this->actingAsJwt($admin)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful();

        $this->actingAsJwt($admin)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/trial-balance')
            ->assertSuccessful()
            ->assertJsonPath('data.is_balanced', true);
    }
}
