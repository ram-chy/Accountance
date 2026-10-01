<?php

namespace Tests\Feature\Reports;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 6 profit and loss statement.
 *
 * The sign convention is the point of most of these: revenue must be positive
 * when it is credited, expenses positive when debited, whatever the journal
 * direction that produced them. Reading the raw debit-positive net instead would
 * make ordinarily-profitable companies look like they lost money.
 */
class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_reports_revenue_expenses_and_net_profit(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '1000.0000', '2027-01-15');
        $this->postJournal($user, $company, $accounts['expense'], $accounts['cash'], '400.0000', '2027-01-20');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss')
            ->assertSuccessful()
            ->assertJsonPath('data.revenue.total', '1000.0000')
            ->assertJsonPath('data.expenses.total', '400.0000')
            ->assertJsonPath('data.net_profit', '600.0000')
            ->assertJsonPath('data.is_profitable', true)
            ->assertJsonPath('data.revenue.accounts.0.code', '4000')
            ->assertJsonPath('data.expenses.accounts.0.code', '5000');
    }

    #[Test]
    public function it_reports_a_loss_when_expenses_exceed_revenue(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '100.0000', '2027-01-15');
        $this->postJournal($user, $company, $accounts['expense'], $accounts['cash'], '250.0000', '2027-01-20');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss')
            ->assertSuccessful()
            ->assertJsonPath('data.net_profit', '-150.0000')
            ->assertJsonPath('data.is_profitable', false);
    }

    #[Test]
    public function the_date_window_limits_the_statement(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '100.0000', '2027-01-10');
        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '200.0000', '2027-02-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss?from_date=2027-02-01&to_date=2027-02-28')
            ->assertSuccessful()
            ->assertJsonPath('data.revenue.total', '200.0000')
            ->assertJsonPath('data.net_profit', '200.0000');
    }

    #[Test]
    public function a_draft_journal_never_appears(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '100.0000', '2027-01-15');
        $this->createDraftJournal($user, $company, $accounts['cash'], $accounts['revenue'], '9000.0000', '2027-01-16');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss')
            ->assertSuccessful()
            ->assertJsonPath('data.revenue.total', '100.0000');
    }

    #[Test]
    public function another_companys_activity_never_appears(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $accountsA = $this->makeTransactionAccounts($companyA);
        $accountsB = $this->makeTransactionAccounts($companyB);

        $this->postJournal($user, $companyA, $accountsA['cash'], $accountsA['revenue'], '100.0000', '2027-01-15');
        $this->postJournal($user, $companyB, $accountsB['cash'], $accountsB['revenue'], '7777.0000', '2027-01-15');

        $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->getJson('/api/accounting/reports/profit-loss')
            ->assertSuccessful()
            ->assertJsonPath('data.revenue.total', '100.0000');
    }

    #[Test]
    public function accounts_with_no_activity_are_omitted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '100.0000', '2027-01-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss')
            ->assertSuccessful();

        $this->assertCount(1, $response->json('data.revenue.accounts'));
        $this->assertSame([], $response->json('data.expenses.accounts'));
        $this->assertSame('0.0000', $response->json('data.expenses.total'));
    }

    #[Test]
    public function the_report_requires_the_reports_permission(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss')
            ->assertSuccessful();
    }
}
