<?php

namespace Tests\Feature\Accounting;

use App\Enums\RoleName;
use App\Models\AccountingPeriod;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Generating a financial year's monthly accounting periods.
 *
 * The generator is the one place the project walks a fiscal calendar, so these
 * tests pin its boundaries: one period per month, none outside the year, no
 * duplicates on a repeat call, and every generated period attached to the year
 * that produced it.
 */
class FinancialYearPeriodGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    private function manager(): User
    {
        return $this->createUserWithRole(RoleName::Manager);
    }

    private function admin(): User
    {
        return $this->createUserWithRole(RoleName::Admin);
    }

    #[Test]
    public function generating_periods_creates_one_per_month_within_the_year(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/periods/generate")
            ->assertCreated()
            ->assertJsonPath('data.periods_count', 12);

        $this->assertSame(12, AccountingPeriod::query()->count());

        // The boundaries are the year's own, clipped to whole months: April 2027
        // starts on the first and March 2028 ends on the thirty-first.
        $first = AccountingPeriod::query()->orderBy('start_date')->first();
        $last = AccountingPeriod::query()->orderByDesc('end_date')->first();

        $this->assertSame('2027-04-01', $first->start_date->toDateString());
        $this->assertSame('2027-04-30', $first->end_date->toDateString());
        $this->assertSame('2028-03-01', $last->start_date->toDateString());
        $this->assertSame('2028-03-31', $last->end_date->toDateString());
    }

    #[Test]
    public function generation_clips_periods_to_a_year_that_does_not_start_on_a_month_boundary(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        /*
         * A year deliberately misaligned with the calendar. Nothing outside the
         * year may be created even though the first and last months are partial:
         * the generator clips them rather than rounding out to whole months, so
         * every date the year covers is postable and no date it does not cover is.
         */
        $year = FinancialYear::factory()->for($company)->create([
            'name' => 'Stub period',
            'start_date' => '2027-04-15',
            'end_date' => '2028-03-20',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/periods/generate")
            ->assertCreated();

        $first = AccountingPeriod::query()->orderBy('start_date')->first();
        $last = AccountingPeriod::query()->orderByDesc('end_date')->first();

        $this->assertSame('2027-04-15', $first->start_date->toDateString());
        $this->assertSame('2028-03-20', $last->end_date->toDateString());

        // No generated period reaches before the year or past it.
        $this->assertSame(
            0,
            AccountingPeriod::query()
                ->whereDate('start_date', '<', '2027-04-15')
                ->orWhereDate('end_date', '>', '2028-03-20')
                ->count()
        );
    }

    #[Test]
    public function generation_is_idempotent(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        $endpoint = "/api/accounting/financial-years/{$year->getKey()}/periods/generate";

        $this->actingAsJwt($user)->withCompanyContext($company)
            ->postJson($endpoint)->assertCreated()->assertJsonPath('data.periods_count', 12);

        $this->assertSame(12, AccountingPeriod::query()->count());

        /*
         * A second call is the normal case - an operator cannot always tell
         * whether a year was already generated - so it must succeed and change
         * nothing. It reports the year's eventual count, not a delta, because a
         * delta of zero on a fully set-up year would read like a failure.
         */
        $this->actingAsJwt($user)->withCompanyContext($company)
            ->postJson($endpoint)->assertCreated()->assertJsonPath('data.periods_count', 12);

        $this->assertSame(12, AccountingPeriod::query()->count());
    }

    #[Test]
    public function generated_periods_belong_to_the_year_that_created_them(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/periods/generate")
            ->assertCreated();

        // A generated period with no year would silently skip the year check at
        // posting time, which is the defect the year column exists to prevent.
        $this->assertSame(
            12,
            AccountingPeriod::query()->where('financial_year_id', $year->getKey())->count()
        );
    }

    #[Test]
    public function generation_does_not_overwrite_a_period_an_operator_already_created(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        $renamed = AccountingPeriod::factory()->for($company)->forFinancialYear($year)->create([
            'name' => 'Opening month',
            'start_date' => '2027-04-01',
            'end_date' => '2027-04-30',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/periods/generate")
            ->assertCreated();

        // Eleven new months, and the operator's renamed April left exactly as it
        // was: generation adds the missing months, it does not replace decisions.
        $this->assertSame(12, AccountingPeriod::query()->count());
        $this->assertSame('Opening month', $renamed->refresh()->name);
    }

    #[Test]
    public function generation_is_company_scoped(): void
    {
        $user = $this->admin();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        $year = FinancialYear::factory()->for($companyA)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/periods/generate")
            ->assertNotFound();

        $this->assertSame(0, AccountingPeriod::query()->count());
    }

    #[Test]
    public function a_manager_cannot_generate_periods(): void
    {
        $admin = $this->admin();
        $company = $this->createCompanyFor($admin);
        $manager = $this->addMemberTo($company, $this->manager());

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        // Generation writes rows, so it rides on the create permission, which
        // Manager does not hold.
        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/periods/generate")
            ->assertStatus(403);

        $this->assertSame(0, AccountingPeriod::query()->count());
    }
}
