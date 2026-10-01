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
 * The Phase 6 balance sheet.
 *
 * The equation `assets = liabilities + equity` is asserted throughout, because
 * it is the property that proves the section signs and the retained-earnings
 * treatment are jointly correct. A balance sheet that reports the right account
 * amounts but the wrong equation is the failure this suite exists to catch.
 */
class BalanceSheetReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function assets_equal_liabilities_plus_equity_through_retained_earnings(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        // Cash 1000 from revenue: asset on one side, profit on the other.
        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '1000.0000', '2027-01-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/balance-sheet?to_date=2027-01-31')
            ->assertSuccessful();

        $this->assertSame('1000.0000', $response->json('data.assets.total'));
        $this->assertSame('0.0000', $response->json('data.liabilities.total'));
        $this->assertSame('1000.0000', $response->json('data.equity.retained_earnings'));
        $this->assertSame('1000.0000', $response->json('data.equity.total'));
        $this->assertSame('1000.0000', $response->json('data.totals.liabilities_and_equity'));
        $this->assertTrue($response->json('data.totals.is_balanced'));
        $this->assertSame('0.0000', $response->json('data.totals.difference'));
    }

    #[Test]
    public function a_liability_is_reported_positive_and_keeps_the_equation(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '1000.0000', '2027-01-15');
        // Debit an expense, credit a payable: liability 300, profit 700.
        $this->postJournal($user, $company, $accounts['expense'], $accounts['payable'], '300.0000', '2027-01-20');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/balance-sheet?to_date=2027-01-31')
            ->assertSuccessful();

        $this->assertSame('1000.0000', $response->json('data.assets.total'));
        $this->assertSame('300.0000', $response->json('data.liabilities.total'));
        $this->assertSame('700.0000', $response->json('data.equity.retained_earnings'));
        $this->assertSame('1000.0000', $response->json('data.totals.liabilities_and_equity'));
        $this->assertTrue($response->json('data.totals.is_balanced'));
    }

    #[Test]
    public function a_contra_asset_reduces_the_asset_section(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $cash = Account::factory()->for($company)->asset()->create(['code' => '1000', 'name' => 'Cash']);
        $contra = Account::factory()->for($company)->asset()->contra()->create([
            'code' => '1090',
            'name' => 'Accumulated Depreciation',
        ]);
        $revenue = Account::factory()->for($company)->revenue()->create(['code' => '4000', 'name' => 'Sales']);

        $this->postJournal($user, $company, $cash, $revenue, '500.0000', '2027-01-15');
        // Credit the contra asset: it must pull the asset section down, not up.
        $this->postJournal($user, $company, $cash, $contra, '100.0000', '2027-01-20');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/balance-sheet?to_date=2027-01-31')
            ->assertSuccessful();

        // Cash 600, contra -100, so assets are 500 - not 700.
        $this->assertSame('500.0000', $response->json('data.assets.total'));
        $this->assertTrue($response->json('data.totals.is_balanced'));

        $contraRow = collect($response->json('data.assets.accounts'))->firstWhere('code', '1090');
        $this->assertSame('-100.0000', $contraRow['amount']);
    }

    #[Test]
    public function the_as_of_date_caps_the_sheet(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '100.0000', '2027-01-10');
        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '200.0000', '2027-02-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/balance-sheet?to_date=2027-01-31')
            ->assertSuccessful()
            ->assertJsonPath('data.assets.total', '100.0000')
            ->assertJsonPath('data.equity.retained_earnings', '100.0000');
    }

    #[Test]
    public function the_current_period_result_is_reported_but_not_double_counted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '100.0000', '2027-01-10');
        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '50.0000', '2027-02-10');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/balance-sheet?from_date=2027-02-01&to_date=2027-02-28')
            ->assertSuccessful();

        // Equity holds the cumulative result (150); the period result (50) is
        // informational and must not be added on top, or the sheet would not
        // balance.
        $this->assertSame('150.0000', $response->json('data.equity.total'));
        $this->assertSame('150.0000', $response->json('data.equity.retained_earnings'));
        $this->assertSame('50.0000', $response->json('data.current_period_result'));
        $this->assertSame('150.0000', $response->json('data.assets.total'));
        $this->assertTrue($response->json('data.totals.is_balanced'));
    }

    #[Test]
    public function a_draft_journal_never_appears(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '100.0000', '2027-01-15');
        $this->createDraftJournal($user, $company, $accounts['cash'], $accounts['expense'], '9000.0000', '2027-01-16');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/balance-sheet?to_date=2027-01-31')
            ->assertSuccessful()
            ->assertJsonPath('data.assets.total', '100.0000');
    }

    #[Test]
    public function the_report_requires_the_reports_permission(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/balance-sheet')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/balance-sheet')
            ->assertSuccessful()
            ->assertJsonPath('data.totals.is_balanced', true);
    }
}
