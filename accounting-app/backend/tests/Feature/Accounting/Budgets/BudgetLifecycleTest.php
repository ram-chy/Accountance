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
 * The budget lifecycle: draft, edit, approve, revise, and the authorization
 * boundaries around each.
 *
 * The central claim under test is that an approved budget is immutable and a
 * change is a new version, so the approved figures are preserved rather than
 * rewritten. Most cases here exist to hold that line against the obvious ways to
 * cross it - editing after approval, revising a draft, approving something empty,
 * or reaching another company's budget by id.
 */
class BudgetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_accountant_can_raise_a_draft_budget(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $year = $this->makeYear($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/budgets', [
                'financial_year_id' => $year->getKey(),
                'code' => 'FY27-OPEX',
                'name' => 'FY27 Operating Plan',
                'notes' => 'Base case',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.code', 'FY27-OPEX');
    }

    #[Test]
    public function the_financial_year_must_belong_to_the_active_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $otherCompany = $this->createCompanyFor($user, isDefault: false);
        $foreignYear = $this->makeYear($otherCompany);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/budgets', [
                'financial_year_id' => $foreignYear->getKey(),
                'code' => 'X',
                'name' => 'Cross-company',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_accountant_can_edit_a_draft(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $budget = $this->makeBudget($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/budgets/{$budget->getKey()}", [
                'name' => 'Renamed Plan',
                'notes' => 'Updated',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Renamed Plan')
            ->assertJsonPath('data.notes', 'Updated');
    }

    #[Test]
    public function an_approved_budget_cannot_be_edited(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->makeApprovedBudget($user, $company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/budgets/{$budget->getKey()}", ['name' => 'Nope'])
            ->assertStatus(422);
    }

    #[Test]
    public function an_approved_budget_cannot_be_deleted(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->makeApprovedBudget($user, $company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/accounting/budgets/{$budget->getKey()}")
            ->assertStatus(422);
    }

    #[Test]
    public function a_draft_can_be_deleted(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $budget = $this->makeBudget($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/accounting/budgets/{$budget->getKey()}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('budgets', ['id' => $budget->getKey()]);
    }

    #[Test]
    public function an_empty_budget_cannot_be_approved(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->makeBudget($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/approve")
            ->assertStatus(422);
    }

    #[Test]
    public function an_accountant_cannot_approve(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->makeBudgetWithLine($user, $company);

        $accountant = $this->addMemberTo($company, $this->createUserWithRole(RoleName::Accountant));

        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/approve")
            ->assertForbidden();
    }

    #[Test]
    public function a_manager_can_approve_but_not_create(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);
        $budget = $this->makeBudgetWithLine($admin, $company);

        $manager = $this->addMemberTo($company, $this->createUserWithRole(RoleName::Manager));

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/budgets', [
                'financial_year_id' => $budget->financial_year_id,
                'code' => 'NOPE',
                'name' => 'Manager cannot create',
            ])
            ->assertForbidden();

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/approve")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.approved_by', $manager->getKey());
    }

    #[Test]
    public function revising_an_approved_budget_creates_a_new_draft_version_with_copied_lines(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->makeApprovedBudget($user, $company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/revise", ['name' => 'FY27 Plan v2'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.parent_budget_id', $budget->getKey())
            ->assertJsonPath('data.name', 'FY27 Plan v2');

        $revisionId = $response->json('data.id');

        $this->assertDatabaseCount('budget_lines', 2);
        $this->assertDatabaseHas('budget_lines', [
            'budget_id' => $revisionId,
            'account_id' => $budget->lines()->first()->account_id,
        ]);

        // The approved source is untouched.
        $this->assertSame('APPROVED', $budget->fresh()->status->value);
    }

    #[Test]
    public function a_draft_cannot_be_revised(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $budget = $this->makeBudget($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/revise")
            ->assertStatus(422);
    }

    #[Test]
    public function another_companys_budget_is_not_found(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $otherCompany = $this->createCompanyFor($user, isDefault: false);
        $foreign = $this->makeBudget($otherCompany);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/budgets/{$foreign->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function staff_cannot_view_budgets(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $staff = $this->addMemberTo($company, $this->createUserWithRole(RoleName::Staff));

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/budgets')
            ->assertForbidden();
    }

    private function makeYear(Company $company): FinancialYear
    {
        return FinancialYear::factory()->for($company)->create([
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'name' => '2027',
        ]);
    }

    private function makeBudget(Company $company): Budget
    {
        $year = $this->makeYear($company);

        return Budget::factory()->for($company)->create([
            'financial_year_id' => $year->getKey(),
            'code' => 'FY27-'.substr(uniqid(), -5),
        ]);
    }

    private function makeBudgetWithLine(User $user, Company $company): Budget
    {
        $year = $this->makeYear($company);
        $budget = Budget::factory()->for($company)->create([
            'financial_year_id' => $year->getKey(),
            'code' => 'FY27-'.substr(uniqid(), -5),
        ]);
        $account = Account::factory()->for($company)->expense()->create();
        $period = AccountingPeriod::factory()->for($company)->forFinancialYear($year)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => '1000.0000',
            ])
            ->assertCreated();

        return $budget->refresh();
    }

    private function makeApprovedBudget(User $user, Company $company): Budget
    {
        $budget = $this->makeBudgetWithLine($user, $company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/approve")
            ->assertSuccessful();

        return $budget->refresh();
    }
}
