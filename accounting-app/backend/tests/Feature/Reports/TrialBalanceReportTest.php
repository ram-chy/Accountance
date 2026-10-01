<?php

namespace Tests\Feature\Reports;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 6 trial balance.
 *
 * This endpoint is not Phase 4's trial balance under a new URL. Phase 4 places
 * each account's net balance in one column; Phase 6 reports the gross debit and
 * gross credit that moved plus a net figure. The tests below therefore assert
 * the gross columns directly, because a net-only implementation would pass a
 * "totals foot" check while being the wrong report.
 *
 * The properties that must hold are the same as Phase 4's, and they are checked
 * here rather than assumed: drafts are invisible, another company's posted lines
 * are invisible, and the date window is inclusive on both ends.
 */
class TrialBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_reports_gross_debits_and_credits_and_a_net_balance_per_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '1000.0000', '2027-01-15');
        // Reverse part of it, so each account has both a debit and a credit and
        // the gross columns differ from the net ones.
        $this->postJournal($user, $company, $revenue, $cash, '300.0000', '2027-01-20');

        $rows = collect($this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance')
            ->assertSuccessful()
            ->json('data.rows'))->keyBy('code');

        $this->assertSame('1000.0000', $rows['1000']['debit_total']);
        $this->assertSame('300.0000', $rows['1000']['credit_total']);
        $this->assertSame('700.0000', $rows['1000']['net_balance']);

        $this->assertSame('300.0000', $rows['4000']['debit_total']);
        $this->assertSame('1000.0000', $rows['4000']['credit_total']);
        $this->assertSame('-700.0000', $rows['4000']['net_balance']);
    }

    #[Test]
    public function the_totals_foot_and_the_report_reports_balanced(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '1175.2500', '2027-01-15');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance')
            ->assertSuccessful()
            ->assertJsonPath('data.totals.debit_total', '1175.2500')
            ->assertJsonPath('data.totals.credit_total', '1175.2500')
            ->assertJsonPath('data.totals.net_balance', '0.0000')
            ->assertJsonPath('data.totals.is_balanced', true)
            ->assertJsonPath('data.totals.difference', '0.0000');
    }

    #[Test]
    public function a_net_zero_account_is_hidden_by_default_and_shown_on_request(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // Two journals that cancel: every account ends with debits equal to its
        // credits.
        $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-01-10');
        $this->postJournal($user, $company, $revenue, $cash, '100.0000', '2027-01-11');

        $default = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance')
            ->assertSuccessful();

        $this->assertSame([], $default->json('data.rows'));

        $withZero = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance?include_zero_balances=1')
            ->assertSuccessful();

        $this->assertCount(2, $withZero->json('data.rows'));
        // Hiding a cancelled account must not unbalance the footing: it adds the
        // same amount to both sides. Both accounts carry 100 on each side here,
        // so the visible total is 200 while the net is zero.
        $this->assertSame('200.0000', $withZero->json('data.totals.debit_total'));
        $this->assertSame('200.0000', $withZero->json('data.totals.credit_total'));
        $this->assertSame('0.0000', $withZero->json('data.totals.net_balance'));
    }

    #[Test]
    public function a_draft_journal_never_appears(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '1000.0000', '2027-01-15');
        $this->createDraftJournal($user, $company, $cash, $revenue, '5000.0000', '2027-01-16');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance')
            ->assertSuccessful()
            ->assertJsonPath('data.totals.debit_total', '1000.0000');
    }

    #[Test]
    public function another_companys_posted_lines_never_appear(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cashA, $revenueA] = $this->makeCashAndRevenueAccounts($companyA);
        [$cashB, $revenueB] = $this->makeCashAndRevenueAccounts($companyB);

        $this->postJournal($user, $companyA, $cashA, $revenueA, '1000.0000', '2027-01-15');
        $this->postJournal($user, $companyB, $cashB, $revenueB, '7777.0000', '2027-01-15');

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson('/api/accounting/reports/trial-balance')
            ->assertSuccessful()
            ->assertJsonPath('data.totals.debit_total', '1000.0000');
    }

    #[Test]
    public function the_date_window_is_inclusive_on_both_ends(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-01-10');
        $this->postJournal($user, $company, $cash, $revenue, '200.0000', '2027-02-10');
        $this->postJournal($user, $company, $cash, $revenue, '400.0000', '2027-03-10');

        $february = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance?from_date=2027-02-01&to_date=2027-02-28')
            ->assertSuccessful();

        $this->assertSame('200.0000', $february->json('data.totals.debit_total'));

        // Exactly the day the movement is dated must include it.
        $exact = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance?from_date=2027-02-10&to_date=2027-02-10')
            ->assertSuccessful();

        $this->assertSame('200.0000', $exact->json('data.totals.debit_total'));
    }

    #[Test]
    public function the_from_and_to_aliases_match_the_canonical_names(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-01-10');
        $this->postJournal($user, $company, $cash, $revenue, '200.0000', '2027-02-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance?from=2027-02-01&to=2027-02-28')
            ->assertSuccessful()
            ->assertJsonPath('data.totals.debit_total', '200.0000')
            ->assertJsonPath('data.period.from', '2027-02-01')
            ->assertJsonPath('data.period.to', '2027-02-28');
    }

    #[Test]
    public function a_backwards_date_window_is_rejected(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance?from_date=2027-03-01&to_date=2027-01-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.to_date.0', 'The to date must be on or after the from date.');
    }

    #[Test]
    public function the_report_requires_the_reports_permission(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance')
            ->assertStatus(403);

        // Granting only the report permission is enough; the report does not
        // borrow the ledger permission.
        $user->givePermissionTo(PermissionName::ReportsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_a_company_the_user_does_not_belong_to(): void
    {
        $user = $this->accountant();
        $this->createCompanyFor($user);
        $stranger = $this->createUnrelatedCompany();

        $this->actingAsJwt($user)
            ->withCompanyContext($stranger)
            ->getJson('/api/accounting/reports/trial-balance')
            ->assertStatus(403);
    }

    #[Test]
    public function it_only_returns_accounts_belonging_to_the_active_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // An account in another company with no movement must never be joined in
        // by code or by a missing company filter.
        Account::factory()->for($this->createUnrelatedCompany())->asset()->create([
            'code' => '1000',
            'name' => 'Someone Else Cash',
        ]);

        $this->postJournal($user, $company, $cash, $revenue, '10.0000', '2027-01-10');

        $codes = collect($this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/trial-balance')
            ->json('data.rows'))->pluck('account_id');

        $this->assertSame(
            [$cash->getKey(), $revenue->getKey()],
            $codes->sort()->values()->all()
        );
    }
}
