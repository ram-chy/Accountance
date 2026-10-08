<?php

namespace Tests\Feature\Accounting\Budgets;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Budget versus actual.
 *
 * The actual figures must come from the same posted ledger the P&L reads, so most
 * of these cases are about what is NOT counted: drafts, other companies, and
 * activity outside a line's period. The favourability cases pin the sign rule -
 * a positive variance is good for revenue and bad for expense - which is the one
 * place a budget report can flatter a company by getting it backwards.
 */
class BudgetVarianceReportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_compares_posted_actuals_against_the_plan_with_correct_favour_ability(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $budget = $this->makeBudget($company);

        $this->addLine($user, $company, $budget, $accounts['revenue'], $this->makeYearPeriod($company, $budget, '2027-01-01', '2027-01-31'), '1000.0000');
        $this->addLine($user, $company, $budget, $accounts['expense'], $this->makeYearPeriod($company, $budget, '2027-01-01', '2027-01-31'), '400.0000');

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '1200.0000', '2027-01-15');
        $this->postJournal($user, $company, $accounts['expense'], $accounts['cash'], '500.0000', '2027-01-20');

        $data = $this->variance($user, $company, $budget);

        $revenue = $this->lineFor($data['lines'], $accounts['revenue']);
        $expense = $this->lineFor($data['lines'], $accounts['expense']);

        $this->assertSame('1200.0000', $revenue['actual']);
        $this->assertSame('200.0000', $revenue['variance']);
        $this->assertSame('FAVOURABLE', $revenue['status']);
        $this->assertSame('20.0000', $revenue['variance_percentage']);

        $this->assertSame('500.0000', $expense['actual']);
        $this->assertSame('100.0000', $expense['variance']);
        $this->assertSame('UNFAVOURABLE', $expense['status']);

        $this->assertSame('1200.0000', $data['summary']['revenue']['actual']);
        $this->assertSame('200.0000', $data['summary']['revenue']['variance']);
        $this->assertSame('500.0000', $data['summary']['expenses']['actual']);
        $this->assertSame('700.0000', $data['summary']['net']['actual']);
        $this->assertSame('100.0000', $data['summary']['net']['variance']);
    }

    #[Test]
    public function an_expense_under_plan_is_favourable(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $budget = $this->makeBudget($company);

        $this->addLine($user, $company, $budget, $accounts['expense'], $this->makeYearPeriod($company, $budget, '2027-01-01', '2027-01-31'), '1000.0000');

        $this->postJournal($user, $company, $accounts['expense'], $accounts['cash'], '600.0000', '2027-01-20');

        $data = $this->variance($user, $company, $budget);
        $expense = $this->lineFor($data['lines'], $accounts['expense']);

        $this->assertSame('-400.0000', $expense['variance']);
        $this->assertSame('FAVOURABLE', $expense['status']);
    }

    #[Test]
    public function a_draft_journal_never_affects_the_variance(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $budget = $this->makeBudget($company);

        $this->addLine($user, $company, $budget, $accounts['revenue'], $this->makeYearPeriod($company, $budget, '2027-01-01', '2027-01-31'), '1000.0000');

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '300.0000', '2027-01-15');
        $this->createDraftJournal($user, $company, $accounts['cash'], $accounts['revenue'], '9000.0000', '2027-01-16');

        $data = $this->variance($user, $company, $budget);

        $this->assertSame('300.0000', $this->lineFor($data['lines'], $accounts['revenue'])['actual']);
    }

    #[Test]
    public function another_companys_activity_never_affects_the_variance(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $otherCompany = $this->createCompanyFor($user, isDefault: false);

        $accounts = $this->makeTransactionAccounts($company);
        $otherAccounts = $this->makeTransactionAccounts($otherCompany);
        $budget = $this->makeBudget($company);

        $this->addLine($user, $company, $budget, $accounts['revenue'], $this->makeYearPeriod($company, $budget, '2027-01-01', '2027-01-31'), '1000.0000');

        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '300.0000', '2027-01-15');
        $this->postJournal($user, $otherCompany, $otherAccounts['cash'], $otherAccounts['revenue'], '7777.0000', '2027-01-15');

        $data = $this->variance($user, $company, $budget);

        $this->assertSame('300.0000', $this->lineFor($data['lines'], $accounts['revenue'])['actual']);
    }

    #[Test]
    public function a_zero_budget_reports_a_null_percentage_and_on_target_when_actual_is_zero(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $budget = $this->makeBudget($company);

        $this->addLine($user, $company, $budget, $accounts['expense'], $this->makeYearPeriod($company, $budget, '2027-01-01', '2027-01-31'), '0.0000');

        $data = $this->variance($user, $company, $budget);
        $expense = $this->lineFor($data['lines'], $accounts['expense']);

        $this->assertNull($expense['variance_percentage']);
        $this->assertSame('ON_TARGET', $expense['status']);
    }

    #[Test]
    public function a_staff_member_cannot_read_the_variance_report(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);
        $budget = $this->makeBudget($company);

        $staff = $this->addMemberTo($company, $this->createUserWithRole(RoleName::Staff));

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/budgets/{$budget->getKey()}/variance")
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function variance(User $user, Company $company, Budget $budget): array
    {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/budgets/{$budget->getKey()}/variance")
            ->assertSuccessful();

        return $response->json('data');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function lineFor(array $lines, Account $account): array
    {
        foreach ($lines as $line) {
            if ((int) $line['account_id'] === $account->getKey()) {
                return $line;
            }
        }

        $this->fail("No variance line found for account {$account->getKey()}.");
    }

    private function makeBudget(Company $company): Budget
    {
        $year = FinancialYear::factory()->for($company)->create([
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'name' => '2027',
        ]);

        return Budget::factory()->for($company)->create([
            'financial_year_id' => $year->getKey(),
            'code' => 'FY27-'.substr(uniqid(), -5),
        ]);
    }

    private function makeYearPeriod(Company $company, Budget $budget, string $start, string $end): AccountingPeriod
    {
        return AccountingPeriod::factory()->for($company)->forFinancialYear($budget->financialYear)->create([
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    private function addLine(User $user, Company $company, Budget $budget, Account $account, AccountingPeriod $period, string $amount): void
    {
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => $amount,
            ])
            ->assertCreated();
    }
}
