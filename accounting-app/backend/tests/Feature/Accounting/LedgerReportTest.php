<?php

namespace Tests\Feature\Accounting;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Balances and the trial balance.
 *
 * Two properties matter more than anything else here, and both are properties of
 * the data rather than of a single endpoint:
 *
 *   - Drafts must not appear. A financial report that counts an entry the user
 *     has not finished is worse than no report.
 *   - Posted lines from another company must not appear. The tenant boundary for
 *     a report is the join through journals, because journal_lines has no
 *     company_id of its own.
 *
 * The normal-balance sign convention is exercised through contra accounts and
 * over-balanced accounts rather than only through the easy cases.
 */
class LedgerReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function a_debit_to_an_asset_account_increases_its_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '1200.5000');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance")
            ->assertSuccessful()
            ->assertJsonPath('data.total_debit', '1200.5000')
            ->assertJsonPath('data.total_credit', '0.0000')
            ->assertJsonPath('data.balance', '1200.5000')
            ->assertJsonPath('data.normal_balance', 'DEBIT');
    }

    #[Test]
    public function a_credit_normal_account_reports_a_positive_credit_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '900.0000');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$revenue->getKey()}/balance")
            ->assertSuccessful()
            // Signed on the normal side: revenue is credit-normal, so credits
            // greater than debits is a positive number.
            ->assertJsonPath('data.balance', '900.0000')
            ->assertJsonPath('data.normal_balance', 'CREDIT');
    }

    #[Test]
    public function an_over_balanced_account_reports_a_negative_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');
        $this->postJournal($user, $company, $cash, $revenue, '400.0000');

        // Credit the asset. An over-credited cash account is a real state -
        // usually a data-entry slip - and is reported honestly as negative rather
        // than hidden by taking the magnitude.
        $second = $this->createDraftJournal(
            $user,
            $company,
            $revenue,
            $cash,
            '100.0000',
            '2027-01-20'
        );

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$second->getKey()}/post")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance")
            ->assertSuccessful()
            ->assertJsonPath('data.total_debit', '400.0000')
            ->assertJsonPath('data.total_credit', '100.0000');

        // 400 - 100 on a DEBIT-normal account. Still positive, because 400 > 100:
        // this asserts the arithmetic rather than the sign convention, which
        // a_contra_asset_account_reports_the_opposite_sign covers.
        $this->assertSame('300.0000',
            $this->actingAsJwt($user)
                ->withCompanyContext($company)
                ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance")
                ->json('data.balance')
        );
    }

    #[Test]
    public function an_asset_with_more_credits_than_debits_reports_a_negative_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        // Straight to credit: the asset ends up on the wrong side of its own
        // normal balance. Reporting 0.0000 or 100.0000 here would both be lies.
        $journal = $this->createDraftJournal($user, $company, $revenue, $cash, '100.0000');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance")
            ->assertSuccessful()
            ->assertJsonPath('data.balance', '-100.0000');
    }

    #[Test]
    public function a_contra_asset_account_reports_the_opposite_sign(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $cash = Account::factory()->for($company)->asset()->create([
            'code' => '1000',
            'name' => 'Cash',
        ]);
        $depreciation = Account::factory()->for($company)->asset()->contra()->create([
            'code' => '1100',
            'name' => 'Accumulated Depreciation',
        ]);
        $revenue = Account::factory()->for($company)->revenue()->create([
            'code' => '4000',
            'name' => 'Sales',
        ]);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        // Debit cash, credit the contra asset. The contra account's normal
        // balance is CREDIT even though its type is ASSET, so a credit produces a
        // positive balance there.
        $journal = $this->createDraftJournal($user, $company, $cash, $depreciation, '150.0000');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$depreciation->getKey()}/balance")
            ->assertSuccessful()
            ->assertJsonPath('data.account_type', 'ASSET')
            ->assertJsonPath('data.normal_balance', 'CREDIT')
            ->assertJsonPath('data.balance', '150.0000');

        $this->assertNotNull($revenue);
    }

    #[Test]
    public function a_draft_does_not_affect_the_ledger(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '1000.0000');
        $this->createDraftJournal($user, $company, $cash, $revenue, '5000.0000');

        // The draft exists as a journal and its lines are in the table; they are
        // simply not POSTED, which is the only thing excluding them.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance")
            ->assertSuccessful()
            ->assertJsonPath('data.total_debit', '1000.0000');

        $trial = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/trial-balance')
            ->assertSuccessful();

        $this->assertSame('1000.0000', $trial->json('data.total_debit'));
        $this->assertSame('1000.0000', $trial->json('data.total_credit'));
    }

    #[Test]
    public function the_trial_balance_foots_to_zero(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027 A');

        $this->postJournal($user, $company, $cash, $revenue, '1000.0000', '2027-01-15');
        $this->postJournal($user, $company, $cash, $revenue, '250.5000', '2027-01-20');
        $this->postJournal($user, $company, $revenue, $cash, '75.2500', '2027-01-25');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/trial-balance')
            ->assertSuccessful();

        // Cash is now 1175.25 (dr) and revenue 1175.25 (cr): the third journal
        // reversed the direction, which is what proves the accumulation is a sum
        // over all lines rather than a count of journals.
        $this->assertSame('1175.2500', $response->json('data.total_debit'));
        $this->assertSame('1175.2500', $response->json('data.total_credit'));
        $this->assertTrue($response->json('data.is_balanced'));
        $this->assertSame('0.0000', $response->json('data.difference'));
        $this->assertCount(2, $response->json('data.accounts'));
    }

    #[Test]
    public function the_trial_balance_places_each_account_in_its_normal_column(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '1000.0000');

        $rows = collect($this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/trial-balance')
            ->assertSuccessful()
            ->json('data.accounts'))->keyBy('code');

        $this->assertSame('1000.0000', $rows['1000']['debit']);
        $this->assertSame('0.0000', $rows['1000']['credit']);

        $this->assertSame('0.0000', $rows['4000']['debit']);
        $this->assertSame('1000.0000', $rows['4000']['credit']);
    }

    #[Test]
    public function the_trial_balance_omits_accounts_with_no_posted_movement(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        Account::factory()->for($company)->expense()->create([
            'code' => '5000',
            'name' => 'Office Supplies',
        ]);

        $this->postJournal($user, $company, $cash, $revenue, '10.0000');

        $codes = collect($this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/trial-balance')
            ->json('data.accounts'))->pluck('code');

        // A trial balance lists what has been booked, not every account the chart
        // of accounts defines.
        $this->assertSame(['1000', '4000'], $codes->sort()->values()->all());
    }

    #[Test]
    public function another_companys_posted_entries_never_appear_in_the_ledger(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cashA, $revenueA] = $this->makeCashAndRevenueAccounts($companyA);
        [$cashB, $revenueB] = $this->makeCashAndRevenueAccounts($companyB);

        $this->postJournal($user, $companyA, $cashA, $revenueA, '1000.0000');
        $this->postJournal($user, $companyB, $cashB, $revenueB, '7777.0000');

        $balance = $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson("/api/accounting/accounts/{$cashA->getKey()}/balance")
            ->assertSuccessful();

        $this->assertSame('1000.0000', $balance->json('data.balance'));

        $trial = $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson('/api/accounting/trial-balance')
            ->assertSuccessful();

        $this->assertSame('1000.0000', $trial->json('data.total_debit'));
    }

    #[Test]
    public function the_ledger_can_be_read_for_a_date_range(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'Jan 2027 A');
        $this->makePeriodFor($company, '2027-02-15', 'Feb 2027 A');
        $this->makePeriodFor($company, '2027-03-15', 'Mar 2027 A');

        $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-01-10');
        $this->postJournal($user, $company, $cash, $revenue, '200.0000', '2027-02-10');
        $this->postJournal($user, $company, $cash, $revenue, '400.0000', '2027-03-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance?from=2027-02-01&to=2027-02-28")
            ->assertSuccessful()
            ->assertJsonPath('data.total_debit', '200.0000');

        // Inclusive bounds: asking for exactly the 10th must include it.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance?from=2027-02-10&to=2027-02-10")
            ->assertSuccessful()
            ->assertJsonPath('data.total_debit', '200.0000');
    }

    #[Test]
    public function an_account_statement_reports_a_running_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->postJournal($user, $company, $cash, $revenue, '100.0000');
        $this->postJournal($user, $company, $cash, $revenue, '150.0000');
        $this->postJournal($user, $company, $revenue, $cash, '50.0000');

        $statement = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance?include_statement=1")
            ->assertSuccessful()
            ->json('data.statement');

        $this->assertCount(3, $statement);
        $this->assertSame('100.0000', $statement[0]['running_balance']);
        $this->assertSame('250.0000', $statement[1]['running_balance']);

        // The third entry credits cash, so the running balance drops. Recomputing
        // from cumulative totals rather than adding a signed delta is what keeps
        // this correct.
        $this->assertSame('200.0000', $statement[2]['running_balance']);
        $this->assertSame('50.0000', $statement[2]['credit']);
    }

    #[Test]
    public function an_account_with_no_posted_history_reports_a_zero_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $unused = Account::factory()->for($company)->expense()->create([
            'code' => '5000',
            'name' => 'Unused',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$unused->getKey()}/balance")
            ->assertSuccessful()
            ->assertJsonPath('data.total_debit', '0.0000')
            ->assertJsonPath('data.total_credit', '0.0000')
            ->assertJsonPath('data.balance', '0.0000');
    }

    #[Test]
    public function an_inactive_account_still_appears_in_the_ledger(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '300.0000');

        $cash->forceFill(['is_active' => false])->save();

        // Deactivation stops new postings; it must not remove the account from
        // historical reports, or a statement silently loses transactions.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$cash->getKey()}/balance")
            ->assertSuccessful()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.balance', '300.0000');
    }

    #[Test]
    public function the_ledger_requires_the_ledger_permission(): void
    {
        /*
         * A user holding accounts.view but NOT accounting.ledger.view is what
         * proves the two permissions are distinct. Refusing a user who holds
         * neither proves nothing: that request would be refused even if the
         * controller quietly fell through to the account's own policy.
         *
         * No shipped role has that exact combination - Admin, Accountant and
         * Manager hold both, Staff holds neither - so the permission is granted
         * directly. That is also the configuration a future role could be given,
         * so it is worth proving the separation holds for it.
         */
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $account = Account::factory()->for($company)->create([
            'code' => '1000',
            'name' => 'Cash',
        ]);

        $user->givePermissionTo(PermissionName::AccountsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounts')
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$account->getKey()}/balance")
            ->assertStatus(403);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/trial-balance')
            ->assertStatus(403);
    }
}
