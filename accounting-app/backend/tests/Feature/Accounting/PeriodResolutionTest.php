<?php

namespace Tests\Feature\Accounting;

use App\Enums\FinancialYearStatus;
use App\Enums\JournalStatus;
use App\Enums\PeriodStatus;
use App\Enums\RoleName;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which period owns an accounting date, and what happens when there is none.
 *
 * Resolution is the join every posting passes through, so it is tested from both
 * sides: directly against the resolver for the lookup rules, and through the
 * posting endpoint for the consequence. A resolver test alone would pass even if
 * nothing called it; a posting test alone would not say which part of the
 * resolution was wrong.
 *
 * The three failure answers are kept distinct, because each needs a different
 * action from the user:
 *
 *   no period     - create the period
 *   closed period - the entry is late; reopen the period
 *   closed year   - the whole span is finished
 *
 * Collapsing them into one "invalid period" message would be shorter and would
 * leave the user guessing.
 */
class PeriodResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    private function resolver(): AccountingPeriodResolver
    {
        return app(AccountingPeriodResolver::class);
    }

    #[Test]
    public function a_date_resolves_to_the_period_that_contains_it(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->assertTrue($this->resolver()->findPeriod($company, Carbon::parse('2027-01-15'))->is($period));
    }

    #[Test]
    public function period_boundaries_are_inclusive_on_both_ends(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        // Treating the range as half-open would leave 2027-01-31 belonging to
        // nothing, and that is the last day of an accounting month.
        foreach (['2027-01-01', '2027-01-31'] as $boundary) {
            $this->assertTrue(
                $this->resolver()->findPeriod($company, Carbon::parse($boundary))->is($period),
                "[$boundary] is inside the period range."
            );
        }

        $this->assertNull(
            $this->resolver()->findPeriod($company, Carbon::parse('2027-02-01')),
            'The day after a period ends belongs to no period.'
        );
    }

    #[Test]
    public function resolution_is_scoped_to_one_company(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $this->makePeriodFor($companyA, '2027-01-15', 'January 2027');
        FinancialYear::factory()->for($companyA)->fiscal('2026-04-01')->create();

        // Identical dates, different company. Without the scope this would resolve
        // to company A's calendar and let company B post on a period it never set up.
        $this->assertNull($this->resolver()->findPeriod($companyB, Carbon::parse('2027-01-15')));
        $this->assertNull($this->resolver()->findFinancialYear($companyB, Carbon::parse('2027-01-15')));
    }

    #[Test]
    public function an_uncovered_date_is_not_closed(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->assertNull($this->resolver()->findPeriod($company, Carbon::parse('2027-01-15')));

        // "Not set up yet" is not "finished". Treating them alike would make a draft
        // undeletable before anybody had created a period for its month.
        $this->assertFalse($this->resolver()->isClosed($company, Carbon::parse('2027-01-15')));
        $this->assertFalse($this->resolver()->acceptsPostings($company, Carbon::parse('2027-01-15')));
    }

    #[Test]
    public function the_resolver_reports_period_and_year_together(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create(['name' => '2027-2028']);
        $period = AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->open()->create();

        $resolved = $this->resolver()->resolve($company, Carbon::parse('2027-04-15'));

        $this->assertTrue($resolved['period']->is($period));
        $this->assertTrue($resolved['financial_year']->is($year));
    }

    #[Test]
    public function a_period_inside_a_closed_year_is_not_postable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create(['name' => '2027-2028']);

        AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->open()->create();

        $year->forceFill([
            'status' => FinancialYearStatus::Closed->value,
            'closed_by' => $user->getKey(),
            'closed_at' => now(),
        ])->save();

        // The period's own status still reads OPEN, so this is only true because
        // the resolver consults the year as well as the period.
        $this->assertFalse($this->resolver()->acceptsPostings($company, Carbon::parse('2027-04-15')));
    }

    #[Test]
    public function is_closed_answers_about_the_period_and_not_the_year(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->closed($user)->create(['name' => '2027-2028']);
        $period = AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->open()->create();

        // isClosed() is about the period, which genuinely is open. The year is a
        // separate axis; acceptsPostings() is the one that combines the two.
        $this->assertFalse($this->resolver()->isClosed($company, Carbon::parse('2027-04-15')));
        $this->assertFalse($this->resolver()->acceptsPostings($company, Carbon::parse('2027-04-15')));

        $period->forceFill(['status' => PeriodStatus::Closed->value, 'closed_at' => now()])->save();

        $this->assertTrue($this->resolver()->isClosed($company, Carbon::parse('2027-04-15')));
    }

    #[Test]
    public function posting_a_date_no_period_covers_is_refused_and_says_so(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // Draft creation is allowed without a period. Only posting is not.
        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', '2027-01-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'No accounting period covers this date',
            $this->responseErrors($response)['journal_date']
        );
        $this->assertSame(JournalStatus::Draft, $journal->refresh()->status);
    }

    #[Test]
    public function posting_into_a_closed_period_is_refused_and_names_the_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027', closed: true);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', '2027-01-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(422);

        // The message names the period, because "invalid date" leaves the user with
        // no idea which month is the problem or that it can be reopened.
        $this->assertStringContainsString('January 2027', $this->responseErrors($response)['journal_date']);
        $this->assertSame(JournalStatus::Draft, $journal->refresh()->status);
    }

    #[Test]
    public function posting_into_a_closed_year_is_refused_and_names_the_year(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create(['name' => 'FY 27-28']);
        $period = AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->open()->create();

        $year->forceFill([
            'status' => FinancialYearStatus::Closed->value,
            'closed_by' => $user->getKey(),
            'closed_at' => now(),
        ])->save();

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', '2027-04-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(422);

        // Distinct from the closed-period message: the year is what is finished
        // here, and reopening one month of it would not help.
        $this->assertStringContainsString('FY 27-28', $this->responseErrors($response)['journal_date']);
        $this->assertStringNotContainsString(
            $period->name,
            $this->responseErrors($response)['journal_date'],
            'The period is open, so the message must not blame it.'
        );
    }

    #[Test]
    public function a_period_created_without_a_named_year_is_attached_to_the_derived_year(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/periods', [
                'name' => 'January 2027',
                'start_date' => '2027-01-01',
                'end_date' => '2027-01-31',
            ])
            ->assertCreated()
            ->assertJsonPath('data.financial_year.name', '2026-2027');

        // The derived range comes from the configured fiscal start month (April), so
        // a January period belongs to the year that began the previous April - not to
        // a calendar year invented to match this period.
        $year = FinancialYear::query()->where('company_id', $company->getKey())->sole();

        $this->assertSame('2026-04-01', $year->start_date->toDateString());
        $this->assertSame('2027-03-31', $year->end_date->toDateString());

        // One year, not one per period: twelve monthly periods share it.
        $this->assertSame(1, FinancialYear::query()->where('company_id', $company->getKey())->count());
    }

    #[Test]
    public function a_period_may_not_sit_outside_the_year_it_is_attached_to(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create(['name' => '2027-2028']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/periods', [
                'name' => 'January 2027',
                'start_date' => '2027-01-01',
                'end_date' => '2027-01-31',
                'financial_year_id' => $year->getKey(),
            ]);

        $response->assertStatus(422);

        // Otherwise a date could be claimed by a period whose year does not contain
        // it, and the resolver would then have two candidate years for one day.
        $this->assertStringContainsString('2027-2028', $this->responseErrors($response)['financial_year_id']);
    }

    #[Test]
    public function a_financial_year_of_another_company_cannot_be_named(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $theirYear = FinancialYear::factory()->for($companyA)->fiscal('2027-04-01')->create(['name' => '2027-2028']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->postJson('/api/accounting/periods', [
                'name' => 'April 2027',
                'start_date' => '2027-04-01',
                'end_date' => '2027-04-30',
                'financial_year_id' => $theirYear->getKey(),
            ]);

        $response->assertStatus(422);

        // The id is a foreign key from a request body. Naming another company's
        // year would attach this company's period to it.
        $this->assertStringContainsString(
            'does not belong to this company',
            $this->responseErrors($response)['financial_year_id']
        );
        $this->assertSame(0, AccountingPeriod::query()->where('company_id', $companyB->getKey())->count());
    }

    #[Test]
    public function a_legacy_period_with_no_year_still_resolves_and_posts(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // The shape a period created before Phase 8 still has: no financial_year_id.
        $period = AccountingPeriod::factory()->for($company)->month('2027-01-01')->open()->create();
        $this->assertNull($period->financial_year_id);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', '2027-01-15');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful();

        // A missing year must not read as a closed year, or every pre-existing
        // period would refuse postings after the migration.
        $this->assertSame(JournalStatus::Posted, $journal->refresh()->status);
    }

    #[Test]
    public function the_period_listing_is_filterable_by_financial_year(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year2026 = FinancialYear::factory()->for($company)->fiscal('2026-04-01')->create(['name' => '2026-2027']);
        $year2027 = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create(['name' => '2027-2028']);

        AccountingPeriod::factory()->for($company)->forFinancialYear($year2026)->month('2026-05-01')->open()->create();
        AccountingPeriod::factory()->for($company)->forFinancialYear($year2027)->month('2027-05-01')->open()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/periods?financial_year_id={$year2027->getKey()}")
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'May 2027')
            ->assertJsonPath('data.0.financial_year.name', '2027-2028');
    }

    #[Test]
    public function the_period_listing_is_filterable_by_date_range(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');
        $this->makePeriodFor($company, '2027-02-15', 'February 2027');
        $this->makePeriodFor($company, '2027-03-15', 'March 2027');

        // Overlap semantics, not containment: February is returned for a range that
        // touches only its last day, because February *covers* that day. Matching
        // start_date instead would hide the very period the caller needs.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/periods?from_date=2027-01-15&to_date=2027-02-28')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'January 2027')
            ->assertJsonPath('data.1.name', 'February 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/periods?from_date=2027-02-01')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_company_sees_only_its_own_financial_years(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $theirs = FinancialYear::factory()->for($companyA)->fiscal('2027-04-01')->create(['name' => '2027-2028']);
        FinancialYear::factory()->for($companyB)->fiscal('2027-04-01')->create(['name' => '2027-2028']);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->getJson('/api/accounting/financial-years')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');

        // The scoped route binding 404s before the policy runs, so a foreign year id
        // is not even reachable for a policy to refuse.
        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->getJson("/api/accounting/financial-years/{$theirs->getKey()}")
            ->assertStatus(404);
    }
}
