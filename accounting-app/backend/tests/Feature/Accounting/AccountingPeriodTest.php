<?php

namespace Tests\Feature\Accounting;

use App\Enums\PeriodStatus;
use App\Enums\RoleName;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Accounting periods: creation, the no-overlap rule, closing, and the
 * one-way nature of closing.
 *
 * A period is the only thing standing between a mistyped date and a permanent
 * record, so the overlap rule and the close rule are tested more thoroughly than
 * the CRUD around them.
 */
class AccountingPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    private function admin(): User
    {
        return $this->createUserWithRole(RoleName::Admin);
    }

    #[Test]
    public function an_accountant_can_open_a_period(): void
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
            ->assertJsonPath('data.status', PeriodStatus::Open->value)
            ->assertJsonPath('data.accepts_postings', true)
            ->assertJsonPath('data.start_date', '2027-01-01')
            ->assertJsonPath('data.end_date', '2027-01-31');
    }

    #[Test]
    public function an_overlapping_period_is_rejected(): void
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
            ->assertCreated();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/periods', [
                'name' => 'Mid January',
                'start_date' => '2027-01-15',
                'end_date' => '2027-02-15',
            ]);

        $response->assertStatus(422);

        // The message names the period that is in the way, because "invalid
        // date range" leaves the user with no idea what to change.
        $this->assertStringContainsString('January 2027', $this->responseErrors($response)['start_date']);
        $this->assertSame(1, AccountingPeriod::query()->count());
    }

    #[Test]
    public function adjacent_periods_do_not_overlap(): void
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
            ->assertCreated();

        // Ranges are inclusive on both ends, so the day after a period ends is the
        // first day of the next one. Treating them as exclusive would leave
        // 2027-01-31 with no period at all.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/periods', [
                'name' => 'February 2027',
                'start_date' => '2027-02-01',
                'end_date' => '2027-02-28',
            ])
            ->assertCreated();

        $this->assertSame(2, AccountingPeriod::query()->count());
    }

    #[Test]
    public function two_companies_may_define_the_same_period(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        foreach ([$companyA, $companyB] as $company) {
            $this->actingAsJwt($user)
                ->withCompanyContext($company)
                ->postJson('/api/accounting/periods', [
                    'name' => 'January 2027',
                    'start_date' => '2027-01-01',
                    'end_date' => '2027-01-31',
                ])
                ->assertCreated();
        }

        $this->assertSame(2, AccountingPeriod::query()->where('name', 'January 2027')->count());
    }

    #[Test]
    public function a_period_name_is_unique_within_a_company(): void
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
            ->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/periods', [
                'name' => 'January 2027',
                'start_date' => '2028-01-01',
                'end_date' => '2028-01-31',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/periods', [
                'name' => 'Backwards',
                'start_date' => '2027-01-31',
                'end_date' => '2027-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');

        $this->assertSame(0, AccountingPeriod::query()->count());
    }

    #[Test]
    public function an_open_period_can_be_renamed(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/periods/{$period->getKey()}", [
                'name' => 'January 2027 (corrected)',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'January 2027 (corrected)');
    }

    #[Test]
    public function an_open_period_can_be_narrowed_without_overlapping_another(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $january = $this->makePeriodFor($company, '2027-01-15', 'January 2027');
        $this->makePeriodFor($company, '2027-03-15', 'March 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/periods/{$january->getKey()}", [
                'start_date' => '2027-01-01',
                'end_date' => '2027-01-31',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.end_date', '2027-01-31');
    }

    #[Test]
    public function a_period_cannot_be_widened_into_another_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $january = $this->makePeriodFor($company, '2027-01-15', 'January 2027');
        $this->makePeriodFor($company, '2027-03-15', 'March 2027');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/periods/{$january->getKey()}", [
                'end_date' => '2027-03-31',
            ]);

        $response->assertStatus(422);
        $this->assertSame('2027-01-31', $january->refresh()->end_date->toDateString());
    }

    #[Test]
    public function an_admin_can_close_a_period(): void
    {
        // Only Admin holds accounting.periods.close; the Accountant role
        // deliberately does not, so the grant is exercised here.
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PeriodStatus::Closed->value)
            ->assertJsonPath('data.accepts_postings', false);

        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    #[Test]
    public function an_accountant_cannot_close_a_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close")
            ->assertStatus(403);

        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    #[Test]
    public function a_closed_period_cannot_be_edited_through_the_update_route(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027', closed: true);

        // Reopen is a route of its own since Phase 8, behind accounting.periods.reopen,
        // and it is the only way back to OPEN. Renaming a closed period is not one of
        // them: the closed period is the record of a finished month, and editing it
        // behind the permission that guards reopen would be the same action by a
        // quieter route.
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounting/periods/{$period->getKey()}", [
                'name' => 'Trying to reopen',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('closed period cannot be modified', $this->responseErrors($response)['period']);
    }

    #[Test]
    public function closing_an_already_closed_period_is_rejected(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027', closed: true);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close");

        $response->assertStatus(422);
        $this->assertStringContainsString('already closed', $this->responseErrors($response)['period']);
    }

    #[Test]
    public function a_period_from_another_company_is_not_found(): void
    {
        $user = $this->admin();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $period = $this->makePeriodFor($companyA, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->getJson("/api/accounting/periods/{$period->getKey()}")
            ->assertStatus(404);
    }

    #[Test]
    public function the_period_listing_is_filterable_by_status(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');
        $this->makePeriodFor($company, '2027-02-15', 'February 2027', closed: true);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/periods?status=CLOSED')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'February 2027');
    }
}
