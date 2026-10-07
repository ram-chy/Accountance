<?php

namespace Tests\Feature\Accounting;

use App\Enums\AuditAction;
use App\Enums\FinancialYearStatus;
use App\Enums\PeriodStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Accounting\Controls\AccountingControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The controlled period-end and year-end workflow (Phase 15).
 *
 * Closing is the point past which a period stops accepting entries, so these
 * tests pin down both halves of the Phase 15 contract: a close records who did
 * it and refuses to proceed while a critical accounting control fails, and none
 * of that changes a posted entry. A close is a state change plus an audit row -
 * never a journal, never a repair.
 *
 * The blocking cases deliberately write accounting history the application could
 * not produce itself (an unbalanced posted journal, created through the model)
 * because that is the only way to reach the state the control exists to catch. The
 * application refuses to post such an entry; the control is the independent read
 * that would notice it if something else wrote it.
 */
class PeriodClosingTest extends TestCase
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

    /**
     * A posted journal whose lines do not balance, dated inside the given day's
     * month. Written through the model so the posting service's balance gate is
     * bypassed on purpose.
     */
    private function postUnbalancedJournal(Company $company, string $date = '2027-01-15'): Journal
    {
        $debit = Account::factory()->for($company)->asset()->create(['code' => '1000', 'name' => 'Cash']);
        $credit = Account::factory()->for($company)->revenue()->create(['code' => '4000', 'name' => 'Revenue']);

        $journal = Journal::factory()->for($company)->posted()->create([
            'journal_number' => 'JNL-UNBAL-'.$company->getKey(),
            'journal_date' => $date,
        ]);

        JournalLine::query()->forceCreate([
            'journal_id' => $journal->getKey(),
            'account_id' => $debit->getKey(),
            'line_number' => 1,
            'debit' => '100.0000',
            'credit' => '0.0000',
        ]);

        JournalLine::query()->forceCreate([
            'journal_id' => $journal->getKey(),
            'account_id' => $credit->getKey(),
            'line_number' => 2,
            'debit' => '0.0000',
            'credit' => '90.0000',
        ]);

        return $journal->refresh();
    }

    #[Test]
    public function an_admin_can_close_a_clean_period(): void
    {
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
    public function closing_records_an_audit_event_naming_the_actor_company_and_period(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close")
            ->assertSuccessful();

        $log = AuditLog::query()
            ->where('action', AuditAction::Closed->value)
            ->where('auditable_type', AccountingPeriod::class)
            ->where('auditable_id', $period->getKey())
            ->first();

        $this->assertNotNull($log, 'Closing a period must write an audit record.');
        $this->assertSame($company->getKey(), $log->company_id);
        $this->assertSame($user->getKey(), $log->actor_id);
        $this->assertSame(PeriodStatus::Open->value, $log->before_data['status']);
        $this->assertSame(PeriodStatus::Closed->value, $log->after_data['status']);
    }

    #[Test]
    public function reopening_records_an_audit_event(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027', closed: true);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/reopen")
            ->assertSuccessful();

        $log = AuditLog::query()
            ->where('action', AuditAction::Reopened->value)
            ->where('auditable_type', AccountingPeriod::class)
            ->where('auditable_id', $period->getKey())
            ->first();

        $this->assertNotNull($log, 'Reopening a period must write an audit record.');
        $this->assertSame(PeriodStatus::Closed->value, $log->before_data['status']);
        $this->assertSame(PeriodStatus::Open->value, $log->after_data['status']);
    }

    #[Test]
    public function a_period_with_an_unbalanced_posted_journal_cannot_be_closed(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->postUnbalancedJournal($company, '2027-01-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close");

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be closed', $this->responseErrors($response)['period']);
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    #[Test]
    public function a_period_with_a_line_pointing_at_another_companys_account_cannot_be_closed(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $other = $this->createUnrelatedCompany();
        $foreignAccount = Account::factory()->for($other)->asset()->create();
        $ownAccount = Account::factory()->for($company)->revenue()->create();

        $journal = Journal::factory()->for($company)->posted()->create([
            'journal_number' => 'JNL-FOREIGN-ACC',
            'journal_date' => '2027-01-15',
        ]);

        JournalLine::query()->forceCreate([
            'journal_id' => $journal->getKey(),
            'account_id' => $foreignAccount->getKey(),
            'line_number' => 1,
            'debit' => '100.0000',
            'credit' => '0.0000',
        ]);

        JournalLine::query()->forceCreate([
            'journal_id' => $journal->getKey(),
            'account_id' => $ownAccount->getKey(),
            'line_number' => 2,
            'debit' => '0.0000',
            'credit' => '100.0000',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close");

        $response->assertStatus(422);
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    #[Test]
    public function a_second_close_after_a_committed_close_is_rejected(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close")
            ->assertSuccessful();

        // A serialised loser: the row lock made the first close commit before the
        // second read the state, so the second observes CLOSED and is refused.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close")
            ->assertStatus(422);

        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
        $this->assertSame(1, AuditLog::query()
            ->where('action', AuditAction::Closed->value)
            ->where('auditable_id', $period->getKey())
            ->count());
    }

    #[Test]
    public function the_closing_check_reports_a_clean_period_as_eligible_without_closing_it(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/periods/{$period->getKey()}/closing-check")
            ->assertSuccessful()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.already_closed', false)
            ->assertJsonPath('data.period.status', PeriodStatus::Open->value)
            ->assertJsonStructure(['data' => [
                'period',
                'eligible',
                'already_closed',
                'summary' => ['PASS', 'WARNING', 'FAIL'],
                'findings',
                'blocking_findings',
                'warning_findings',
                'required_action',
            ]]);

        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    #[Test]
    public function the_closing_check_names_the_blocking_findings(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->postUnbalancedJournal($company, '2027-01-15');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/periods/{$period->getKey()}/closing-check")
            ->assertSuccessful()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.blocking_findings.0.control_code', AccountingControlService::UNBALANCED_POSTED_JOURNAL)
            ->assertJsonPath('data.blocking_findings.0.status', 'FAIL');
    }

    #[Test]
    public function an_accountant_can_read_the_closing_check_but_cannot_close(): void
    {
        $admin = $this->admin();
        $company = $this->createCompanyFor($admin);
        $accountant = $this->addMemberTo($company, $this->accountant());
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/periods/{$period->getKey()}/closing-check")
            ->assertSuccessful();

        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$period->getKey()}/close")
            ->assertForbidden();

        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    #[Test]
    public function a_staff_user_cannot_read_the_closing_check(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);
        $period = $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/periods/{$period->getKey()}/closing-check")
            ->assertForbidden();
    }

    #[Test]
    public function a_period_from_another_company_is_not_reachable(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();
        $foreignPeriod = $this->makePeriodFor($other, '2027-01-15', 'January 2027');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/periods/{$foreignPeriod->getKey()}/closing-check")
            ->assertNotFound();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$foreignPeriod->getKey()}/close")
            ->assertNotFound();
    }

    #[Test]
    public function finalizing_a_year_records_an_audit_event(): void
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
            ->assertSuccessful();

        $log = AuditLog::query()
            ->where('action', AuditAction::Closed->value)
            ->where('auditable_type', FinancialYear::class)
            ->where('auditable_id', $year->getKey())
            ->first();

        $this->assertNotNull($log, 'Finalizing a financial year must write an audit record.');
        $this->assertSame($company->getKey(), $log->company_id);
    }

    #[Test]
    public function finalizing_a_year_is_blocked_when_a_critical_control_fails(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $year = FinancialYear::factory()->for($company)->fiscal('2027-04-01')->create([
            'name' => '2027-2028',
        ]);

        AccountingPeriod::factory()->for($company)->forFinancialYear($year)
            ->month('2027-04-01')->closed()->create();

        $this->postUnbalancedJournal($company, '2027-04-10');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/accounting/financial-years/{$year->getKey()}/close");

        $response->assertStatus(422);
        $this->assertStringContainsString('critical accounting controls', $this->responseErrors($response)['financial_year']);
        $this->assertSame(FinancialYearStatus::Open, $year->refresh()->status);
    }

    #[Test]
    public function closing_a_period_writes_no_journal(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');
        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', '2027-01-10');

        $this->actingAsJwt($user)->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful();

        $before = Journal::query()->where('company_id', $company->getKey())->count();

        $this->actingAsJwt($user)->withCompanyContext($company)
            ->postJson("/api/accounting/periods/{$journal->period()->getKey()}/close")
            ->assertSuccessful();

        $this->assertSame($before, Journal::query()->where('company_id', $company->getKey())->count());
    }
}
