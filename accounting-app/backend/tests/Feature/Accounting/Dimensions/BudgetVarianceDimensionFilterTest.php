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
 * The dimension-aware budget variance report (Phase 17 §21-23).
 *
 * A filtered variance is "the plan for this label against the ledger for this
 * label": BOTH sides are narrowed by the same filter, and the plan lines that do
 * not carry the label drop out of view. The unfiltered report is unchanged, which
 * is the compatibility guarantee the phase asked for - the filter extends the
 * Phase 16 report rather than replacing it.
 */
class BudgetVarianceDimensionFilterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_scopes_both_the_plan_and_the_actuals_to_the_dimension_value(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);
        $budget = $this->makeBudget($company);
        $period = $this->makeYearPeriod($company, $budget);

        // One labelled revenue plan line and one unlabelled expense plan line.
        $this->addLine($user, $company, $budget, $accounts['revenue'], $period, '1000.0000', [
            ['dimension_id' => $dimension->getKey(), 'value_id' => $value->getKey()],
        ]);
        $this->addLine($user, $company, $budget, $accounts['expense'], $period, '500.0000');

        // Labelled revenue actuals and unlabelled expense actuals.
        $this->postJournalWithDimensions($user, $company, $accounts['cash'], $accounts['revenue'], '600.0000', '2027-01-15', creditDimensions: [[
            'dimension_id' => $dimension->getKey(),
            'value_id' => $value->getKey(),
        ]]);
        $this->postJournal($user, $company, $accounts['expense'], $accounts['cash'], '900.0000', '2027-01-20');

        $data = $this->variance($user, $company, $budget, '?dimension_value_id='.$value->getKey());

        // Only the labelled line survives the filter.
        $this->assertCount(1, $data['lines']);
        $line = $data['lines'][0];

        $this->assertSame($accounts['revenue']->getKey(), (int) $line['account_id']);
        $this->assertSame('1000.0000', $line['budget']);
        $this->assertSame('600.0000', $line['actual']);
        $this->assertSame('-400.0000', $line['variance']);
        $this->assertSame('UNFAVOURABLE', $line['status']);

        $this->assertSame('1000.0000', $data['summary']['revenue']['budget']);
        $this->assertSame('600.0000', $data['summary']['revenue']['actual']);
        $this->assertSame('0.0000', $data['summary']['expenses']['budget']);
        $this->assertSame($value->getKey(), $data['dimension_filter']['dimension_value_id']);
        $this->assertNull($data['dimension_filter']['dimension_id']);
    }

    #[Test]
    public function the_unfiltered_report_still_shows_every_line(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);
        $budget = $this->makeBudget($company);
        $period = $this->makeYearPeriod($company, $budget);

        $this->addLine($user, $company, $budget, $accounts['revenue'], $period, '1000.0000', [
            ['dimension_id' => $dimension->getKey(), 'value_id' => $value->getKey()],
        ]);
        $this->addLine($user, $company, $budget, $accounts['expense'], $period, '500.0000');

        $data = $this->variance($user, $company, $budget);

        $this->assertCount(2, $data['lines']);
        $this->assertArrayNotHasKey('dimension_filter', $data);
    }

    #[Test]
    public function filtering_by_dimension_id_covers_all_its_values(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        [$dimension, $firstValue] = $this->makeCostCenter($company);
        $secondValue = FinancialDimensionValue::factory()->for($dimension, 'dimension')->create();
        $budget = $this->makeBudget($company);
        $period = $this->makeYearPeriod($company, $budget);

        $this->addLine($user, $company, $budget, $accounts['revenue'], $period, '1000.0000', [
            ['dimension_id' => $dimension->getKey(), 'value_id' => $firstValue->getKey()],
        ]);

        $this->postJournalWithDimensions($user, $company, $accounts['cash'], $accounts['revenue'], '600.0000', '2027-01-15', creditDimensions: [[
            'dimension_id' => $dimension->getKey(),
            'value_id' => $firstValue->getKey(),
        ]]);
        $this->postJournalWithDimensions($user, $company, $accounts['cash'], $accounts['revenue'], '300.0000', '2027-01-16', creditDimensions: [[
            'dimension_id' => $dimension->getKey(),
            'value_id' => $secondValue->getKey(),
        ]]);
        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '200.0000', '2027-01-17');

        $data = $this->variance($user, $company, $budget, '?dimension_id='.$dimension->getKey());

        $this->assertSame('900.0000', $data['lines'][0]['actual']);
        $this->assertSame('-100.0000', $data['lines'][0]['variance']);
        $this->assertSame($dimension->getKey(), $data['dimension_filter']['dimension_id']);
    }

    #[Test]
    public function it_refuses_a_dimension_from_another_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $otherCompany = $this->createCompanyFor($user, isDefault: false);
        [$foreignDimension] = $this->makeCostCenter($otherCompany);
        $budget = $this->makeBudget($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/budgets/{$budget->getKey()}/variance?dimension_id=".$foreignDimension->getKey())
            ->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function variance(User $user, Company $company, Budget $budget, string $query = ''): array
    {
        return $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/budgets/{$budget->getKey()}/variance{$query}")
            ->assertSuccessful()
            ->json('data');
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
