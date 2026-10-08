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
 * Budget line rules.
 *
 * The rules under test are the ones the database cannot express on its own: the
 * account must belong to the same company as the budget, must be a profit-and-loss
 * account, must be active, and the period must be inside the budget's own financial
 * year. Each of these is a join the unique index cannot make, so each is enforced in
 * BudgetLineService and verified here through the endpoint.
 */
class BudgetLineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_line_can_be_added_to_a_draft_budget(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->draft($company);
        $account = Account::factory()->for($company)->expense()->create();
        $period = $this->periodFor($company, $budget);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '2500.5000',
                'description' => 'Rent',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '2500.5000')
            ->assertJsonPath('data.account_id', $account->getKey())
            ->assertJsonPath('data.description', 'Rent');
    }

    #[Test]
    public function a_duplicate_line_for_the_same_account_and_period_is_refused(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        [$budget, $account, $period] = $this->draftWithLine($user, $company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '500.0000',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_balance_sheet_account_is_refused(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->draft($company);
        $asset = Account::factory()->for($company)->asset()->create();
        $period = $this->periodFor($company, $budget);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $asset->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '500.0000',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_inactive_account_is_refused(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->draft($company);
        $account = Account::factory()->for($company)->expense()->create(['is_active' => false]);
        $period = $this->periodFor($company, $budget);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '500.0000',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_period_outside_the_budgets_financial_year_is_refused(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->draft($company);

        $otherYear = FinancialYear::factory()->for($company)->create([
            'name' => 'FY-other-'.uniqid(),
        ]);
        $foreignPeriod = AccountingPeriod::factory()->for($company)->forFinancialYear($otherYear)->create();
        $account = Account::factory()->for($company)->expense()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $foreignPeriod->getKey(),
                'amount' => '500.0000',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_account_from_another_company_is_refused(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $otherCompany = $this->createCompanyFor($user, isDefault: false);

        $budget = $this->draft($company);
        $period = $this->periodFor($company, $budget);
        $foreignAccount = Account::factory()->for($otherCompany)->expense()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $foreignAccount->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '500.0000',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_amount_may_be_zero_but_not_negative(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->draft($company);
        $period = $this->periodFor($company, $budget);
        $account = Account::factory()->for($company)->expense()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '-1.0000',
            ])
            ->assertStatus(422);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '0.0000',
            ])
            ->assertCreated();
    }

    #[Test]
    public function lines_of_an_approved_budget_are_frozen(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        [$budget, $account, $period] = $this->draftWithLine($user, $company);
        $line = $budget->lines()->first();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/approve")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/budgets/{$budget->getKey()}/lines/{$line->getKey()}", [
                'amount' => '9999.0000',
            ])
            ->assertStatus(422);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/accounting/budgets/{$budget->getKey()}/lines/{$line->getKey()}")
            ->assertStatus(422);
    }

    #[Test]
    public function another_budgets_line_id_is_not_found(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        [, , $line] = $this->draftWithLine($user, $company);

        $otherBudget = $this->draft($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/accounting/budgets/{$otherBudget->getKey()}/lines/{$line->getKey()}")
            ->assertNotFound();
    }

    private function draft(Company $company): Budget
    {
        $year = FinancialYear::factory()->for($company)->create([
            'name' => 'FY-'.uniqid(),
        ]);

        return Budget::factory()->for($company)->create([
            'financial_year_id' => $year->getKey(),
            'code' => 'FY-'.substr(uniqid(), -5),
        ]);
    }

    private function periodFor(Company $company, Budget $budget): AccountingPeriod
    {
        return AccountingPeriod::factory()->for($company)->create([
            'financial_year_id' => $budget->financial_year_id,
        ]);
    }

    /**
     * @return array{0: Budget, 1: Account, 2: AccountingPeriod}
     */
    private function draftWithLine(User $user, Company $company): array
    {
        $budget = $this->draft($company);
        $account = Account::factory()->for($company)->expense()->create();
        $period = $this->periodFor($company, $budget);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '1000.0000',
            ])
            ->assertCreated();

        return [$budget->refresh(), $account, $period];
    }
}
