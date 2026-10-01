<?php

namespace Tests\Feature\Accounting;

use App\Enums\FinancialYearStatus;
use App\Enums\RoleName;
use App\Models\AccountingPeriod;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Financial years: creation, the no-overlap rule, company scoping, and the
 * permission split.
 *
 * A financial year is the container above accounting periods. The rules tested
 * here are the ones the container adds over the periods themselves: it is
 * company-scoped, years may not overlap inside a company, and - the point of the
 * whole layer - a year with an open period inside it cannot declare itself
 * finished.
 */
class FinancialYearTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->createUserWithRole(RoleName::Admin);
    }

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    private function manager(): User
    {
        return $this->createUserWithRole(RoleName::Manager);
    }

    private function staff(): User
    {
        return $this->createUserWithRole(RoleName::Staff);
    }

    #[Test]
    public function an_accountant_can_create_a_financial_year(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => '2027-2028',
                'start_date' => '2027-04-01',
                'end_date' => '2028-03-31',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', '2027-2028')
            ->assertJsonPath('data.status', FinancialYearStatus::Open->value)
            ->assertJsonPath('data.start_date', '2027-04-01')
            ->assertJsonPath('data.end_date', '2028-03-31')
            ->assertJsonPath('data.accepts_postings', true)
            // No periods yet, so it is not closeable. The client does not have to
            // reimplement "all periods closed" to know that.
            ->assertJsonPath('data.can_close', false);
    }

    #[Test]
    public function an_overlapping_financial_year_is_rejected(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => '2027-2028',
                'start_date' => '2027-04-01',
                'end_date' => '2028-03-31',
            ])
            ->assertCreated();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => 'Overlaps',
                'start_date' => '2028-01-01',
                'end_date' => '2028-12-31',
            ]);

        $response->assertStatus(422);

        // The message names the year in the way, because "these dates are not
        // allowed" leaves the user with nothing to adjust.
        $this->assertStringContainsString('2027-2028', $this->responseErrors($response)['start_date']);
        $this->assertSame(1, FinancialYear::query()->count());
    }

    #[Test]
    public function adjacent_financial_years_do_not_overlap(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => '2027-2028',
                'start_date' => '2027-04-01',
                'end_date' => '2028-03-31',
            ])
            ->assertCreated();

        // Inclusive on both ends: the day after one year ends is the first day of
        // the next, and treating the ranges as exclusive would leave 2028-03-31
        // in neither year.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => '2028-2029',
                'start_date' => '2028-04-01',
                'end_date' => '2029-03-31',
            ])
            ->assertCreated();

        $this->assertSame(2, FinancialYear::query()->count());
    }

    #[Test]
    public function two_companies_may_define_the_same_financial_year(): void
    {
        $first = $this->accountant();
        $second = $this->accountant();
        $companyA = $this->createCompanyFor($first);
        $companyB = $this->createCompanyFor($second);

        $payload = [
            'name' => '2027-2028',
            'start_date' => '2027-04-01',
            'end_date' => '2028-03-31',
        ];

        $this->actingAsJwt($first)->withCompanyContext($companyA)
            ->postJson('/api/accounting/financial-years', $payload)->assertCreated();

        // The overlap rule is per company, so the identical range in another
        // company is not a conflict.
        $this->actingAsJwt($second)->withCompanyContext($companyB)
            ->postJson('/api/accounting/financial-years', $payload)->assertCreated();

        $this->assertSame(2, FinancialYear::query()->count());
    }

    #[Test]
    public function a_financial_year_name_is_unique_within_a_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => '2027-2028',
                'start_date' => '2027-04-01',
                'end_date' => '2028-03-31',
            ])
            ->assertCreated();

        // Non-overlapping dates, same name: the name itself is the second guard,
        // and it is what a double-submitted form trips over.
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => '2027-2028',
                'start_date' => '2028-04-01',
                'end_date' => '2029-03-31',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('already in use', $this->responseErrors($response)['name']);
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => 'Backwards',
                'start_date' => '2028-03-31',
                'end_date' => '2027-04-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    #[Test]
    public function a_financial_year_from_another_company_is_not_found(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        // The same user belongs to both companies, so the 404 below is the scoped
        // route binding refusing a foreign record - not the membership check
        // refusing a foreign company.
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $year = FinancialYear::factory()->for($companyA)->create([
            'start_date' => '2027-04-01',
            'end_date' => '2028-03-31',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->getJson("/api/accounting/financial-years/{$year->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function a_manager_can_view_but_not_create_financial_years(): void
    {
        $admin = $this->admin();
        $company = $this->createCompanyFor($admin);
        $manager = $this->addMemberTo($company, $this->manager());

        FinancialYear::factory()->for($company)->create([
            'start_date' => '2027-04-01',
            'end_date' => '2028-03-31',
        ]);

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/financial-years')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');

        // Manager holds no .create permission anywhere in this project, and the
        // fiscal calendar does not become the first exception.
        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/financial-years', [
                'name' => 'Manager attempt',
                'start_date' => '2028-04-01',
                'end_date' => '2029-03-31',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function a_staff_user_cannot_view_financial_years(): void
    {
        $admin = $this->admin();
        $company = $this->createCompanyFor($admin);
        $staff = $this->addMemberTo($company, $this->staff());

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/financial-years')
            ->assertStatus(403);
    }

    #[Test]
    public function the_financial_year_listing_is_filterable_by_status(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        FinancialYear::factory()->for($company)->open()->create([
            'name' => 'Open',
            'start_date' => '2027-04-01',
            'end_date' => '2028-03-31',
        ]);
        FinancialYear::factory()->for($company)->closed($user)->create([
            'name' => 'Closed',
            'start_date' => '2028-04-01',
            'end_date' => '2029-03-31',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/financial-years?status=CLOSED')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Closed');
    }

    #[Test]
    public function a_closed_financial_year_cannot_be_modified(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->closed($user)->create([
            'name' => '2027-2028',
            'start_date' => '2027-04-01',
            'end_date' => '2028-03-31',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/financial-years/{$year->getKey()}", [
                'name' => 'Renamed after close',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('closed financial year cannot be modified', $this->responseErrors($response)['financial_year']);
        $this->assertSame('2027-2028', $year->refresh()->name);
    }

    #[Test]
    public function a_financial_year_cannot_be_shortened_while_it_contains_a_period_outside_the_new_range(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->create([
            'name' => '2027-2028',
            'start_date' => '2027-04-01',
            'end_date' => '2028-03-31',
        ]);

        AccountingPeriod::factory()->for($company)->forFinancialYear($year)->create([
            'name' => 'March 2028',
            'start_date' => '2028-03-01',
            'end_date' => '2028-03-31',
        ]);

        // Shrinking the year to end in February would leave March 2028 belonging
        // to a year that does not contain it, and the resolver would then have two
        // candidate years for those dates.
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/financial-years/{$year->getKey()}", [
                'end_date' => '2028-02-28',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('outside the new range', $this->responseErrors($response)['end_date']);
    }
}
