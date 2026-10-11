<?php

namespace Tests\Feature\Accounting\Dimensions;

use App\Enums\FinancialDimensionType;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\FinancialDimension;
use App\Models\FinancialDimensionValue;
use App\Models\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 17 journal-line dimension assignment.
 *
 * A dimension label is analytical metadata on a line, in scope only while the
 * draft is editable: it is validated against the ACTIVE company, and it becomes
 * part of the permanent record the moment the journal posts - after which the
 * same immutability that protects the amounts protects the labels.
 */
class JournalLineDimensionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_the_dimension_labels_that_accompany_a_draft_journal_line(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'Labelled revenue',
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000', [[
                        'dimension_id' => $dimension->getKey(),
                        'value_id' => $value->getKey(),
                    ]]),
                ],
            ])
            ->assertCreated();

        $journal = Journal::findOrFail($response->json('data.id'));
        $revenueLine = $journal->lines()->firstWhere('account_id', $revenue->getKey());
        $cashLine = $journal->lines()->firstWhere('account_id', $cash->getKey());

        $this->assertSame(1, $revenueLine->journalLineDimensions()->count());
        $link = $revenueLine->journalLineDimensions()->first();

        $this->assertSame($dimension->getKey(), (int) $link->financial_dimension_id);
        $this->assertSame($value->getKey(), (int) $link->financial_dimension_value_id);

        // A line that was not labelled carries no labels - assignment is per
        // line, never inferred from a sibling.
        $this->assertSame(0, $cashLine->journalLineDimensions()->count());

        $response
            ->assertJsonPath('data.lines.1.dimensions.0.dimension_id', $dimension->getKey())
            ->assertJsonPath('data.lines.1.dimensions.0.value_id', $value->getKey())
            ->assertJsonPath('data.lines.0.dimensions', []);
    }

    #[Test]
    public function replacing_the_lines_replaces_their_dimension_labels(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$firstDimension, $firstValue] = $this->makeCostCenter($company);
        [$secondDimension, $secondValue] = $this->makeCostCenter($company);

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'To be re-labelled',
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000', [[
                        'dimension_id' => $firstDimension->getKey(),
                        'value_id' => $firstValue->getKey(),
                    ]]),
                ],
            ])
            ->assertCreated();

        $journal = Journal::findOrFail($created->json('data.id'));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", [
                'lines' => [
                    $this->linePayload($cash, '1200.0000', '0'),
                    $this->linePayload($revenue, '0', '1200.0000', [[
                        'dimension_id' => $secondDimension->getKey(),
                        'value_id' => $secondValue->getKey(),
                    ]]),
                ],
            ])
            ->assertSuccessful();

        $journal->refresh();
        $revenueLine = $journal->lines()->firstWhere('account_id', $revenue->getKey());

        $this->assertSame(1, $revenueLine->journalLineDimensions()->count());
        $link = $revenueLine->journalLineDimensions()->first();
        $this->assertSame($secondDimension->getKey(), (int) $link->financial_dimension_id);
        $this->assertSame($secondValue->getKey(), (int) $link->financial_dimension_value_id);
    }

    #[Test]
    public function dropping_dimensions_from_a_line_clears_the_old_label(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'To be un-labelled',
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000', [[
                        'dimension_id' => $dimension->getKey(),
                        'value_id' => $value->getKey(),
                    ]]),
                ],
            ])
            ->assertCreated();

        $journal = Journal::findOrFail($created->json('data.id'));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", [
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000'),
                ],
            ])
            ->assertSuccessful();

        $revenueLine = $journal->refresh()->lines()->firstWhere('account_id', $revenue->getKey());

        $this->assertSame(0, $revenueLine->journalLineDimensions()->count());
    }

    #[Test]
    public function it_refuses_a_value_from_another_companys_dimension(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $otherCompany = $this->createCompanyFor($user, isDefault: false);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$foreignDimension, $foreignValue] = $this->makeCostCenter($otherCompany);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'Wrong company',
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000', [[
                        'dimension_id' => $foreignDimension->getKey(),
                        'value_id' => $foreignValue->getKey(),
                    ]]),
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            'The selected financial dimension does not belong to the active company or is inactive.',
            $response->json('errors')['lines.1.dimensions.0.dimension_id'][0],
        );
    }

    #[Test]
    public function it_refuses_a_value_that_belongs_to_a_different_dimension(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension] = $this->makeCostCenter($company);
        [, $otherDimensionValue] = $this->makeCostCenter($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'Crossed wires',
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000', [[
                        'dimension_id' => $dimension->getKey(),
                        'value_id' => $otherDimensionValue->getKey(),
                    ]]),
                ],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function it_refuses_an_inactive_dimension(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company, active: false);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'Inactive dimension',
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000', [[
                        'dimension_id' => $dimension->getKey(),
                        'value_id' => $value->getKey(),
                    ]]),
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            'The selected financial dimension does not belong to the active company or is inactive.',
            $response->json('errors')['lines.1.dimensions.0.dimension_id'][0],
        );
    }

    #[Test]
    public function it_refuses_two_values_for_the_same_dimension_on_one_line(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension, $firstValue] = $this->makeCostCenter($company);
        $secondValue = FinancialDimensionValue::factory()->for($dimension, 'dimension')->create();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'One dimension, two values',
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000', [[
                        'dimension_id' => $dimension->getKey(),
                        'value_id' => $firstValue->getKey(),
                    ], [
                        'dimension_id' => $dimension->getKey(),
                        'value_id' => $secondValue->getKey(),
                    ]]),
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            'A line may be assigned to the same financial dimension only once.',
            $response->json('errors')['lines.1.dimensions.1.dimension_id'][0],
        );
    }

    #[Test]
    public function posting_a_journal_freezes_its_dimension_labels(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        [$dimension, $value] = $this->makeCostCenter($company);

        $this->makePeriodFor($company, '2027-01-15', 'P 2027-01-assign');

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'Posted with labels',
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000', [[
                        'dimension_id' => $dimension->getKey(),
                        'value_id' => $value->getKey(),
                    ]]),
                ],
            ])
            ->assertCreated();

        $journal = Journal::findOrFail($created->json('data.id'));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", [
                'lines' => [
                    $this->linePayload($cash, '1000.0000', '0'),
                    $this->linePayload($revenue, '0', '1000.0000'),
                ],
            ])
            ->assertStatus(422);

        $revenueLine = $journal->refresh()->lines()->firstWhere('account_id', $revenue->getKey());

        $this->assertSame(1, $revenueLine->journalLineDimensions()->count());
        $this->assertSame($value->getKey(), (int) $revenueLine->journalLineDimensions()->first()->financial_dimension_value_id);
    }

    /**
     * @return array{0: FinancialDimension, 1: FinancialDimensionValue}
     */
    private function makeCostCenter(Company $company, bool $active = true): array
    {
        $dimension = FinancialDimension::factory()
            ->for($company)
            ->type(FinancialDimensionType::CostCenter)
            ->create(['is_active' => $active]);

        $value = FinancialDimensionValue::factory()
            ->for($dimension, 'dimension')
            ->create(['is_active' => $active]);

        return [$dimension, $value];
    }

    /**
     * @param  array<int, array{dimension_id: int, value_id: int}>  $dimensions
     * @return array<string, mixed>
     */
    private function linePayload(Account $account, string $debit, string $credit, array $dimensions = []): array
    {
        $line = [
            'account_id' => $account->getKey(),
            'debit' => $debit,
            'credit' => $credit,
        ];

        if ($dimensions !== []) {
            $line['dimensions'] = $dimensions;
        }

        return $line;
    }
}
