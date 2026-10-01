<?php

namespace Tests\Feature\Reports;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 6 general ledger.
 *
 * The running balance is raw debit-positive by design: a debit adds and a credit
 * subtracts, whatever the account's type. The tests below pin that down for a
 * credit-normal account as well as a debit-normal one, because a normal-balance
 * sign here would make every liability ledger run backwards and still look
 * plausible in isolation.
 */
class GeneralLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function it_lists_posted_movements_with_a_running_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-01-15');
        $this->postJournal($user, $company, $cash, $revenue, '150.0000', '2027-01-20');
        $this->postJournal($user, $company, $revenue, $cash, '50.0000', '2027-01-25');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/general-ledger?account_id={$cash->getKey()}")
            ->assertSuccessful();

        $this->assertSame('0.0000', $response->json('data.opening_balance'));
        $this->assertSame('250.0000', $response->json('data.period_debits'));
        $this->assertSame('50.0000', $response->json('data.period_credits'));
        $this->assertSame('200.0000', $response->json('data.closing_balance'));

        $rows = $response->json('data.rows');
        $this->assertCount(3, $rows);
        $this->assertSame('100.0000', $rows[0]['running_balance']);
        $this->assertSame('250.0000', $rows[1]['running_balance']);
        // The third entry credits cash, so the running balance drops.
        $this->assertSame('200.0000', $rows[2]['running_balance']);
    }

    #[Test]
    public function a_credit_normal_account_reports_a_raw_negative_closing_and_a_positive_signed_one(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-01-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/general-ledger?account_id={$revenue->getKey()}")
            ->assertSuccessful();

        // Raw debit-positive: a credit-only account is negative.
        $this->assertSame('-100.0000', $response->json('data.closing_balance'));
        // On the normal (credit) side it is positive.
        $this->assertSame('100.0000', $response->json('data.closing_balance_signed'));
        $this->assertSame('CREDIT', $response->json('data.account.normal_balance'));
    }

    #[Test]
    public function it_carries_an_opening_balance_from_before_the_window(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '400.0000', '2027-01-15');
        $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-02-10');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/general-ledger?account_id={$cash->getKey()}&from_date=2027-02-01&to_date=2027-02-28")
            ->assertSuccessful();

        $this->assertSame('400.0000', $response->json('data.opening_balance'));
        $this->assertSame('100.0000', $response->json('data.period_debits'));
        $this->assertSame('500.0000', $response->json('data.closing_balance'));
        $this->assertCount(1, $response->json('data.rows'));
    }

    #[Test]
    public function a_draft_journal_never_appears(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-01-15');
        $this->createDraftJournal($user, $company, $cash, $revenue, '5000.0000', '2027-01-16');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/general-ledger?account_id={$cash->getKey()}")
            ->assertSuccessful();

        $this->assertCount(1, $response->json('data.rows'));
        $this->assertSame('100.0000', $response->json('data.closing_balance'));
    }

    #[Test]
    public function an_account_with_no_movement_reports_zero_and_no_rows(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $account = Account::factory()->for($company)->expense()->create([
            'code' => '5000',
            'name' => 'Unused Expense',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/general-ledger?account_id={$account->getKey()}")
            ->assertSuccessful();

        $this->assertSame('0.0000', $response->json('data.opening_balance'));
        $this->assertSame('0.0000', $response->json('data.closing_balance'));
        $this->assertSame([], $response->json('data.rows'));
    }

    #[Test]
    public function an_account_from_another_company_is_rejected(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $other = $this->createCompanyFor($user, isDefault: false);
        [$otherCash] = $this->makeCashAndRevenueAccounts($other);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/general-ledger?account_id={$otherCash->getKey()}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    #[Test]
    public function the_account_id_is_required(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/general-ledger')
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    #[Test]
    public function the_report_requires_the_reports_permission(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/reports/general-ledger?account_id=1')
            ->assertStatus(403);

        $user->givePermissionTo(PermissionName::ReportsView->value);

        $account = Account::factory()->for($company)->asset()->create([
            'code' => '1000',
            'name' => 'Cash',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/general-ledger?account_id={$account->getKey()}")
            ->assertSuccessful();
    }

    #[Test]
    public function it_lists_movements_in_creation_order_within_a_day(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // Two journals on the same date. Only the id tiebreak makes the order
        // deterministic; without it the running balance would be unstable.
        $first = $this->postJournal($user, $company, $cash, $revenue, '100.0000', '2027-01-15');
        $second = $this->postJournal($user, $company, $cash, $revenue, '200.0000', '2027-01-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/general-ledger?account_id={$cash->getKey()}")
            ->assertSuccessful();

        $ids = collect($response->json('data.rows'))->pluck('journal_id')->all();

        $this->assertSame([$first->getKey(), $second->getKey()], $ids);
        $this->assertSame('100.0000', $response->json('data.rows.0.running_balance'));
        $this->assertSame('300.0000', $response->json('data.rows.1.running_balance'));
    }
}
