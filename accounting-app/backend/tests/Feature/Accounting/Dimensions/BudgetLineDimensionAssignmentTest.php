<?php

namespace Tests\Feature\Accounting\Dimensions;

use App\Enums\FinancialDimensionType;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\Company;
use App\Models\FinancialDimension;
use App\Models\FinancialDimensionValue;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 17 budget-line dimension assignment.
 *
 * A plan line carries the same analytical labels as a journal line, so the two
 * share the one validator. What matters for a budget specifically is that a
 * revision - the only way to change an approved plan - carries its source's
 * labels forward, so a versioned plan keeps its scoping across versions.
 */
class BudgetLineDimensionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_the_dimension_labels_that_accompany_a_budget_line(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);
        $budget = $this->makeBudget($company);

        $this->addLine($user, $company, $budget, $revenue, $this->makeYearPeriod($company, $budget), '1000.0000', [
            ['dimension_id' => $dimension->getKey(), 'value_id' => $value->getKey()],
        ]);

        $line = $budget->refresh()->lines()->firstOrFail();

        $this->assertSame(1, $line->budgetLineDimensions()->count());
        $link = $line->budgetLineDimensions()->first();

        $this->assertSame($dimension->getKey(), (int) $link->financial_dimension_id);
        $this->assertSame($value->getKey(), (int) $link->financial_dimension_value_id);
    }

    #[Test]
    public function updating_a_line_replaces_its_dimension_labels(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);
        $budget = $this->makeBudget($company);

        $this->addLine($user, $company, $budget, $revenue, $this->makeYearPeriod($company, $budget), '1000.0000', [
            ['dimension_id' => $dimension->getKey(), 'value_id' => $value->getKey()],
        ]);

        $line = $budget->refresh()->lines()->firstOrFail();

        // An empty array is an explicit clear, not an omission.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/budgets/{$budget->getKey()}/lines/{$line->getKey()}", [
                'dimensions' => [],
            ])
            ->assertSuccessful();

        $this->assertSame(0, $line->refresh()->budgetLineDimensions()->count());
    }

    #[Test]
    public function it_refuses_a_dimension_from_another_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $otherCompany = $this->createCompanyFor($user, isDefault: false);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$foreignDimension, $foreignValue] = $this->makeCostCenter($otherCompany);
        $budget = $this->makeBudget($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", [
                'account_id' => $revenue->getKey(),
                'accounting_period_id' => $this->makeYearPeriod($company, $budget)->getKey(),
                'amount' => '1000.0000',
                'dimensions' => [
                    ['dimension_id' => $foreignDimension->getKey(), 'value_id' => $foreignValue->getKey()],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $budget->refresh()->lines()->count());
    }

    #[Test]
    public function revising_an_approved_budget_copies_the_dimension_labels_to_the_new_version(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);
        $budget = $this->makeBudget($company);

        $this->addLine($user, $company, $budget, $revenue, $this->makeYearPeriod($company, $budget), '1000.0000', [
            ['dimension_id' => $dimension->getKey(), 'value_id' => $value->getKey()],
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/approve")
            ->assertSuccessful();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/revise")
            ->assertCreated();

        $revision = Budget::findOrFail($response->json('data.id'));
        $revisionLine = $revision->lines()->with('budgetLineDimensions')->firstOrFail();

        $this->assertSame(1, $revisionLine->budgetLineDimensions->count());
        $this->assertSame($dimension->getKey(), (int) $revisionLine->budgetLineDimensions->first()->financial_dimension_id);
        $this->assertSame($value->getKey(), (int) $revisionLine->budgetLineDimensions->first()->financial_dimension_value_id);

        // The approved source's labels are untouched - it remains the record of
        // what was authorised.
        $sourceLine = $budget->refresh()->lines()->with('budgetLineDimensions')->firstOrFail();
        $this->assertSame(1, $sourceLine->budgetLineDimensions->count());
        $this->assertSame($value->getKey(), (int) $sourceLine->budgetLineDimensions->first()->financial_dimension_value_id);
    }

    /**
     * @return array{0: FinancialDimension, 1: FinancialDimensionValue}
     */
    private function makeCostCenter(Company $company): array
    {
        $dimension = FinancialDimension::factory()
            ->for($company)
            ->type(FinancialDimensionType::CostCenter)
            ->create();

        $value = FinancialDimensionValue::factory()->for($dimension, 'dimension')->create();

        return [$dimension, $value];
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

    private function makeYearPeriod(Company $company, Budget $budget): AccountingPeriod
    {
        return AccountingPeriod::factory()->for($company)->forFinancialYear($budget->financialYear)->create([
            'start_date' => '2027-01-01',
            'end_date' => '2027-01-31',
        ]);
    }

    /**
     * @param  array<int, array{dimension_id: int, value_id: int}>  $dimensions
     */
    private function addLine(
        User $user,
        Company $company,
        Budget $budget,
        Account $account,
        AccountingPeriod $period,
        string $amount,
        array $dimensions = []
    ): void {
        $payload = [
            'account_id' => $account->getKey(),
            'accounting_period_id' => $period->getKey(),
            'amount' => $amount,
        ];

        if ($dimensions !== []) {
            $payload['dimensions'] = $dimensions;
        }

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/budgets/{$budget->getKey()}/lines", $payload)
            ->assertCreated();
    }
}
