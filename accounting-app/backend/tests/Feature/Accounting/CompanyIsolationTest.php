<?php

namespace Tests\Feature\Accounting;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Company isolation across every accounting endpoint.
 *
 * The specification asks for this to be tested at every endpoint rather than
 * once for the module, because the isolation mechanism differs by endpoint:
 *
 *   - Listings are protected by an explicit company_id filter in the query.
 *   - Single-resource reads are protected by company-scoped route binding.
 *   - Writes are protected by both, because the body carries no company id and
 *     the service re-checks the referenced accounts.
 *
 * A single test of "listing" would leave the other two mechanisms unverified.
 * 404 rather than 403 is the expected response throughout: a 403 would confirm
 * that the id exists, which is itself a disclosure.
 */
class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    /**
     * @return array{0: User, 1: Company, 2: Company}
     */
    private function twoCompanies(): array
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        return [$user, $companyA, $companyB];
    }

    #[Test]
    public function the_account_listing_contains_only_the_active_companys_accounts(): void
    {
        [$user, $companyA, $companyB] = $this->twoCompanies();

        Account::factory()->for($companyA)->create(['code' => '1000', 'name' => 'Cash A']);
        Account::factory()->for($companyB)->create(['code' => '1000', 'name' => 'Cash B']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson('/api/accounts')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Cash A', $response->json('data.0.name'));
    }

    #[Test]
    public function another_companys_account_cannot_be_read(): void
    {
        [$user, $companyA, $companyB] = $this->twoCompanies();

        $accountB = Account::factory()->for($companyB)->create(['code' => '1000']);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson("/api/accounts/{$accountB->getKey()}")
            ->assertStatus(404);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->putJson("/api/accounts/{$accountB->getKey()}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->deleteJson("/api/accounts/{$accountB->getKey()}")
            ->assertStatus(404);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->postJson("/api/accounts/{$accountB->getKey()}/deactivate")
            ->assertStatus(404);

        $this->assertDatabaseHas('accounts', ['id' => $accountB->getKey(), 'name' => $accountB->name]);
    }

    #[Test]
    public function another_companys_account_balance_is_not_found(): void
    {
        [$user, $companyA, $companyB] = $this->twoCompanies();

        $accountB = Account::factory()->for($companyB)->asset()->create(['code' => '1000']);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson("/api/accounting/accounts/{$accountB->getKey()}/balance")
            ->assertStatus(404);
    }

    #[Test]
    public function the_journal_listing_contains_only_the_active_companys_journals(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cashA, $revenueA] = $this->makeCashAndRevenueAccounts($companyA);
        [$cashB, $revenueB] = $this->makeCashAndRevenueAccounts($companyB);

        $this->createDraftJournal($user, $companyA, $cashA, $revenueA, '10.00', '2027-01-10');
        $this->createDraftJournal($user, $companyB, $cashB, $revenueB, '99.00', '2027-01-10');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson('/api/journals')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->assertSame('10.0000', $response->json('data.0.lines.0.debit'));
    }

    #[Test]
    public function another_companys_journal_cannot_be_read_or_modified(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cashB, $revenueB] = $this->makeCashAndRevenueAccounts($companyB);
        $journalB = $this->createDraftJournal($user, $companyB, $cashB, $revenueB);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson("/api/journals/{$journalB->getKey()}")
            ->assertStatus(404);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->putJson("/api/journals/{$journalB->getKey()}", ['description' => 'Hijacked'])
            ->assertStatus(404);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->deleteJson("/api/journals/{$journalB->getKey()}")
            ->assertStatus(404);

        $this->assertDatabaseHas('journals', [
            'id' => $journalB->getKey(),
            'description' => $journalB->description,
        ]);
    }

    #[Test]
    public function a_journal_cannot_borrow_another_companys_account(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cashA] = $this->makeCashAndRevenueAccounts($companyA);
        [, $revenueB] = $this->makeCashAndRevenueAccounts($companyB);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'lines' => [
                    ['account_id' => $cashA->getKey(), 'debit' => '10.0000', 'credit' => '0'],
                    ['account_id' => $revenueB->getKey(), 'debit' => '0', 'credit' => '10.0000'],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Journal::query()->count());
    }

    #[Test]
    public function the_period_listing_contains_only_the_active_companys_periods(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $this->makePeriodFor($companyA, '2027-01-15', 'January A');
        $this->makePeriodFor($companyB, '2027-01-15', 'January B');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson('/api/accounting/periods')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->assertSame('January A', $response->json('data.0.name'));
    }

    #[Test]
    public function an_account_cannot_be_parented_to_another_companys_account(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $parentB = Account::factory()->for($companyB)->asset()->create([
            'code' => '1000',
            'name' => 'Parent B',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->postJson('/api/accounts', [
                'code' => '1100',
                'name' => 'Child A',
                'account_type' => 'ASSET',
                'parent_id' => $parentB->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    #[Test]
    public function two_companies_may_each_hold_the_same_account_code(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        Account::factory()->for($companyB)->create(['code' => '1000']);

        // Both companies use code 1000. The unique index is per company, so the
        // create succeeds - which is the behaviour the spec asks for, and is the
        // control case for the isolation tests above.
        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->postJson('/api/accounts', [
                'code' => '1000',
                'name' => 'Cash A',
                'account_type' => 'ASSET',
            ])
            ->assertCreated();

        $this->assertSame(2, Account::query()->where('code', '1000')->count());
    }

    #[Test]
    public function a_user_who_is_not_a_member_of_the_active_company_is_refused(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);

        $outsider = $this->createUserWithRole(RoleName::Admin);
        $theirCompany = $this->createCompanyFor($outsider);

        $this->actingAsJwt($outsider)
            ->withCompanyContext($companyA)
            ->getJson('/api/accounts')
            ->assertStatus(403);
    }
}
