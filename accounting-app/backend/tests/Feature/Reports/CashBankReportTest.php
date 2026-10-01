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
 * The Phase 6 cash and bank report.
 *
 * The report returns the general-ledger shape for one account; the tests assert
 * that shape (opening, dated movements, period totals, closing) rather than a
 * cash-specific one, because a second implementation would be the same query.
 * Tenancy is asserted through the account rule: an account id from another
 * company must be refused before any ledger is read.
 */
class CashBankReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_returns_ledger_activity_for_a_cash_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '1000.0000', '2027-01-15');
        $this->postJournal($user, $company, $revenue, $cash, '300.0000', '2027-01-20');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/cash-bank?account_id='.$cash->getKey())
            ->assertSuccessful()
            ->assertJsonPath('data.account.id', $cash->getKey())
            ->assertJsonPath('data.opening_balance', '0.0000')
            ->assertJsonPath('data.period_debits', '1000.0000')
            ->assertJsonPath('data.period_credits', '300.0000')
            ->assertJsonPath('data.closing_balance', '700.0000');
    }

    #[Test]
    public function it_requires_an_account_id(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/cash-bank')
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    #[Test]
    public function it_refuses_an_account_from_another_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $foreign = Account::factory()->for($this->createUnrelatedCompany())->asset()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/cash-bank?account_id='.$foreign->getKey())
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    #[Test]
    public function the_report_requires_the_reports_permission(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/cash-bank')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/cash-bank?account_id=1')
            ->assertStatus(422);
    }
}
