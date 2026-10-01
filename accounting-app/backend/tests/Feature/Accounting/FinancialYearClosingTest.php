<?php

namespace Tests\Feature\Accounting;

use App\Enums\FinancialYearStatus;
use App\Enums\JournalStatus;
use App\Enums\PeriodStatus;
use App\Enums\RoleName;
use App\Models\AccountingPeriod;
use App\Models\FinancialYear;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Closing a financial year.
 *
 * The rule is deliberately narrow: every period of the year must be closed before
 * the year can be. Closing writes no journal - retained earnings is derived by the
 * Phase 6 balance sheet from posted lines, so a year-end closing entry would be
 * counted twice. These tests assert both halves: the precondition is enforced,
 * and the close is a state change with no accounting side effect.
 */
class FinancialYearClosingTest extends TestCase
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

    #[Test]
    public function a_year_with_an_open_period_cannot_be_closed(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        AccountingPeriod::factory()->for($company)->forFinancialYear($year)->month('2027-04-01')->open()->create();
        AccountingPeriod::factory()->for($company)->forFinancialYear($year)->month('2027-05-01')->closed()->create();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/close");

        $response->assertStatus(422);
        $this->assertStringContainsString('must be closed', $this->responseErrors($response)['financial_year']);
        $this->assertSame(FinancialYearStatus::Open, $year->refresh()->status);
    }

    #[Test]
    public function a_year_with_no_periods_cannot_be_closed(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        // "All periods closed" is vacuously true with no periods, and a year that
        // contains nothing should not be declarable as finished. The service
        // requires at least one period.
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/close");

        $response->assertStatus(422);
        $this->assertSame(FinancialYearStatus::Open, $year->refresh()->status);
    }

    #[Test]
    public function an_admin_can_close_a_year_once_every_period_is_closed(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->closed()->create();
        AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-05-01')->closed()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/close")
            ->assertSuccessful()
            ->assertJsonPath('data.status', FinancialYearStatus::Closed->value)
            ->assertJsonPath('data.can_close', false);

        $this->assertSame(FinancialYearStatus::Closed, $year->refresh()->status);
    }

    #[Test]
    public function closing_records_who_closed_it_and_when(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->closed()->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/close")
            ->assertSuccessful()
            ->assertJsonPath('data.closed_by', $user->getKey())
            ->assertJsonPath('data.status', FinancialYearStatus::Closed->value);

        $this->assertNotNull($year->refresh()->closed_at);
    }

    #[Test]
    public function an_accountant_cannot_close_a_year(): void
    {
        $admin = $this->admin();
        $company = $this->createCompanyFor($admin);
        $accountant = $this->addMemberTo($company, $this->accountant());

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->closed()->create();

        // Accountant holds periods.create/update but not periods.close, exactly as
        // in Phase 4, and the year follows the same split.
        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/close")
            ->assertStatus(403);

        $this->assertSame(FinancialYearStatus::Open, $year->refresh()->status);
    }

    #[Test]
    public function closing_an_already_closed_year_is_rejected(): void
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
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/close");

        $response->assertStatus(422);
        $this->assertStringContainsString('already closed', $this->responseErrors($response)['financial_year']);
    }

    #[Test]
    public function closing_a_year_writes_no_journal(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // A real posted entry, so closing operates on a year that genuinely
        // contains accounting history.
        $yearPeriod = AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->open()->create();

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', '2027-04-10');

        $this->actingAsJwt($user)->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful();

        $before = Journal::query()->where('company_id', $company->getKey())->count();

        $yearPeriod->forceFill(['status' => PeriodStatus::Closed->value])->save();

        $this->actingAsJwt($user)->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/close")
            ->assertSuccessful();

        // No closing journal. Retained earnings is derived by the balance sheet
        // from posted lines through a date; a generated closing entry would be
        // counted a second time.
        $this->assertSame($before, Journal::query()->where('company_id', $company->getKey())->count());
    }

    #[Test]
    public function a_closed_year_rejects_posting_even_if_a_period_is_left_open(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        // Deliberately inconsistent state, created directly so the year check can
        // be exercised on its own: all periods must be closed to close the year
        // through the API, so a closed year with an open period is only reachable
        // by a data fix. It proves assertPostableDate() consults the year as well
        // as the period rather than trusting the period status alone.
        AccountingPeriod::factory()->for($company)->forFinancialYear($year)->create([
            'name' => 'April 2027',
            'start_date' => '2027-04-01',
            'end_date' => '2027-04-30',
            'status' => PeriodStatus::Open->value,
        ]);

        $year->forceFill([
            'status' => FinancialYearStatus::Closed->value,
            'closed_by' => $user->getKey(),
            'closed_at' => now(),
        ])->save();

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '50.00', '2027-04-10');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(422);
        $this->assertStringContainsString('financial year', $this->responseErrors($response)['journal_date']);
        $this->assertSame(JournalStatus::Draft, $journal->refresh()->status);
    }
}
