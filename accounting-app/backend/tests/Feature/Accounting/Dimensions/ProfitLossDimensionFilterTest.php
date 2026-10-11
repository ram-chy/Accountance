<?php

namespace Tests\Feature\Accounting\Dimensions;

use App\Enums\FinancialDimensionType;
use App\Enums\RoleName;
use App\Models\Company;
use App\Models\FinancialDimension;
use App\Models\FinancialDimensionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dimension-aware profit and loss statement (Phase 17 §18-19).
 *
 * The dimension P&L is the SAME ledger read narrowed by a join, never a stored
 * total, so the property that matters is reconciliation: the company statement
 * must equal the filtered statement plus the explicitly-reported unassigned
 * remainder. If that ever stopped holding, one of the two reads had started
 * inventing figures, and the subtraction here is what would catch it.
 */
class ProfitLossDimensionFilterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_scopes_the_statement_to_lines_carrying_the_dimension_value(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);

        // Labelled revenue and expense...
        $this->postJournalWithDimensions($user, $company, $accounts['cash'], $accounts['revenue'], '1000.0000', '2027-01-15', creditDimensions: [[
            'dimension_id' => $dimension->getKey(),
            'value_id' => $value->getKey(),
        ]]);
        $this->postJournalWithDimensions($user, $company, $accounts['expense'], $accounts['cash'], '400.0000', '2027-01-20', debitDimensions: [[
            'dimension_id' => $dimension->getKey(),
            'value_id' => $value->getKey(),
        ]]);

        // ...and unlabelled revenue and expense on the same accounts.
        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '300.0000', '2027-01-22');
        $this->postJournal($user, $company, $accounts['expense'], $accounts['cash'], '100.0000', '2027-01-25');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss?dimension_value_id='.$value->getKey())
            ->assertSuccessful();

        $response
            ->assertJsonPath('data.revenue.total', '1000.0000')
            ->assertJsonPath('data.expenses.total', '400.0000')
            ->assertJsonPath('data.net_profit', '600.0000')
            ->assertJsonPath('data.dimension_filter.dimension_id', null)
            ->assertJsonPath('data.dimension_filter.dimension_value_id', $value->getKey())
            ->assertJsonPath('data.unassigned.revenue', '300.0000')
            ->assertJsonPath('data.unassigned.expenses', '100.0000')
            ->assertJsonPath('data.unassigned.net', '200.0000');

        // The unfiltered statement is the whole ledger, and the filtered +
        // unassigned halves reconcile against it exactly.
        $full = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('1300.0000', $full['revenue']['total']);
        $this->assertSame('500.0000', $full['expenses']['total']);
        $this->assertSame('800.0000', $full['net_profit']);
        $this->assertArrayNotHasKey('dimension_filter', $full);
        $this->assertArrayNotHasKey('unassigned', $full);
    }

    #[Test]
    public function filtering_by_dimension_id_covers_every_value_of_the_dimension(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        [$dimension, $firstValue] = $this->makeCostCenter($company);
        $secondValue = FinancialDimensionValue::factory()->for($dimension, 'dimension')->create();

        $this->postJournalWithDimensions($user, $company, $accounts['cash'], $accounts['revenue'], '1000.0000', '2027-01-15', creditDimensions: [[
            'dimension_id' => $dimension->getKey(),
            'value_id' => $firstValue->getKey(),
        ]]);
        $this->postJournalWithDimensions($user, $company, $accounts['cash'], $accounts['revenue'], '400.0000', '2027-01-16', creditDimensions: [[
            'dimension_id' => $dimension->getKey(),
            'value_id' => $secondValue->getKey(),
        ]]);
        $this->postJournal($user, $company, $accounts['cash'], $accounts['revenue'], '300.0000', '2027-01-17');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss?dimension_id='.$dimension->getKey())
            ->assertSuccessful()
            ->assertJsonPath('data.revenue.total', '1400.0000')
            ->assertJsonPath('data.dimension_filter.dimension_id', $dimension->getKey())
            ->assertJsonPath('data.dimension_filter.dimension_value_id', null)
            ->assertJsonPath('data.unassigned.revenue', '300.0000');
    }

    #[Test]
    public function it_refuses_a_dimension_from_another_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $otherCompany = $this->createCompanyFor($user, isDefault: false);
        [$foreignDimension] = $this->makeCostCenter($otherCompany);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss?dimension_id='.$foreignDimension->getKey())
            ->assertStatus(422)
            ->assertJsonPath('errors.dimension_id.0', 'The selected financial dimension does not belong to the active company.');
    }

    #[Test]
    public function it_refuses_a_value_that_belongs_to_a_different_dimension(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$dimension] = $this->makeCostCenter($company);
        [, $otherValue] = $this->makeCostCenter($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss?dimension_id='.$dimension->getKey().'&dimension_value_id='.$otherValue->getKey())
            ->assertStatus(422)
            ->assertJsonPath('errors.dimension_value_id.0', 'The selected value does not belong to the selected financial dimension.');
    }

    #[Test]
    public function a_value_may_be_filtered_without_naming_its_dimension(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);

        $this->postJournalWithDimensions($user, $company, $accounts['cash'], $accounts['revenue'], '1000.0000', '2027-01-15', creditDimensions: [[
            'dimension_id' => $dimension->getKey(),
            'value_id' => $value->getKey(),
        ]]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/profit-loss?dimension_value_id='.$value->getKey())
            ->assertSuccessful()
            ->assertJsonPath('data.revenue.total', '1000.0000');
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
}
