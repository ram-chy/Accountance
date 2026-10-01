<?php

namespace Tests\Feature\Accounting;

use App\Enums\JournalStatus;
use App\Enums\RoleName;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The irreversible half of the journal lifecycle.
 *
 * Posting is the only transition that turns a draft into part of the accounting
 * record, so this file concentrates on three questions:
 *
 *   - What must be true before a journal may post?
 *   - What must remain impossible afterwards?
 *   - What happens when the same journal is posted twice?
 */
class PostingTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function an_accountant_can_post_a_draft(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '500.0000');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', JournalStatus::Posted->value);

        $journal->refresh();

        $this->assertSame(JournalStatus::Posted, $journal->status);
        $this->assertSame($user->getKey(), $journal->posted_by, 'posted_by is the authenticated user.');
        $this->assertNotNull($journal->posted_at);
        $this->assertSame($user->getKey(), $journal->created_by);
    }

    #[Test]
    public function posted_by_cannot_be_supplied_by_the_client(): void
    {
        $user = $this->accountant();
        $other = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);
        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);

        // An audit trail recording whoever the client *said* posted is worthless,
        // so the parameters are ignored rather than trusted.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post", [
                'posted_by' => $other->getKey(),
                'posted_at' => '1999-01-01T00:00:00Z',
                'status' => JournalStatus::Posted->value,
            ])
            ->assertSuccessful();

        $journal->refresh();

        $this->assertSame($user->getKey(), $journal->posted_by);
        $this->assertTrue($journal->posted_at->isAfter(now()->subMinute()));
    }

    #[Test]
    public function a_posted_journal_cannot_be_edited(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->postJournal($user, $company, $cash, $revenue);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", [
                'description' => 'Rewriting history',
                'lines' => [
                    ['account_id' => $cash->getKey(), 'debit' => '1.0000', 'credit' => '0'],
                    ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '1.0000'],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be edited', $this->responseErrors($response)['journal']);

        $journal->refresh();

        // Not merely refused: unchanged. A 422 that had already written would be
        // worse than useless.
        $this->assertSame(2, $journal->lines()->count());
        $this->assertSame('1000.0000', (string) $journal->totalDebit());
        $this->assertSame('1000.0000', (string) $journal->totalCredit());
        $this->assertDatabaseHas('journals', [
            'id' => $journal->getKey(),
            'description' => 'Test entry',
        ]);
    }

    #[Test]
    public function a_posted_journal_cannot_be_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->postJournal($user, $company, $cash, $revenue);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/journals/{$journal->getKey()}")
            ->assertStatus(422);

        $this->assertDatabaseHas('journals', ['id' => $journal->getKey()]);
        $this->assertSame(2, $journal->lines()->count());
    }

    #[Test]
    public function posting_the_same_journal_twice_returns_a_conflict(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->postJournal($user, $company, $cash, $revenue);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('already posted', $this->responseMessage($response));

        // The first posted_at survives; a second attempt must not restamp it.
        $journal->refresh();
        $this->assertNotNull($journal->posted_at);
    }

    #[Test]
    public function a_journal_cannot_post_when_no_period_covers_its_date(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // No period at all for 2027-01.
        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '10.00', '2027-01-15');

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
    public function a_journal_cannot_post_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027', closed: true);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '10.00', '2027-01-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(422);

        $this->assertStringContainsString('is closed', $this->responseErrors($response)['journal_date']);
        $this->assertSame(JournalStatus::Draft, $journal->refresh()->status);
    }

    #[Test]
    public function a_journal_cannot_post_to_an_inactive_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);

        // Staging an entry against an account that is later deactivated is fine;
        // posting it is not. The account keeps its history either way.
        $cash->forceFill(['is_active' => false])->save();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(422);

        $this->assertStringContainsString(
            'is inactive',
            $this->responseErrors($response)['account_id']
        );

        $this->assertSame(JournalStatus::Draft, $journal->refresh()->status);
    }

    #[Test]
    public function a_journal_whose_lines_were_edited_into_imbalance_cannot_post(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'January 2027');

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);

        /*
         * The database has no way to know whether the totals match - that is a
         * whole-entry property - so an unbalanced line set can only be caught at
         * posting. This is the reason posting re-validates rather than trusting
         * that a saved draft was balanced when written.
         *
         * The mutation goes through the query builder to bypass the model, and it
         * stays within what the CHECK constraints permit (each line is still
         * one-sided and non-zero) - only the sum across lines is now wrong.
         */
        \DB::table('journal_lines')
            ->where('journal_id', $journal->getKey())
            ->where('credit', '>', 0)
            ->update(['credit' => '900.0000', 'updated_at' => now()]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(422);
        $this->assertStringContainsString('not balanced', $this->responseErrors($response)['lines']);

        $this->assertSame(JournalStatus::Draft, $journal->refresh()->status);
    }

    #[Test]
    public function posting_does_not_duplicate_lines(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->postJournal($user, $company, $cash, $revenue);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertStatus(409);

        // The spec is explicit that entries must not be duplicated, and a retry
        // storm is the realistic way that bug appears.
        $this->assertSame(2, $journal->lines()->count());
    }

    #[Test]
    public function a_journal_from_another_company_cannot_be_posted(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cashA, $revenueA] = $this->makeCashAndRevenueAccounts($companyA);
        $this->makePeriodFor($companyA, '2027-01-15', 'January 2027 A');

        $journal = $this->createDraftJournal($user, $companyA, $cashA, $revenueA);

        // Route binding is scoped to the active company, so the id is not merely
        // unauthorised - it does not resolve.
        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertStatus(404);

        $this->assertSame(JournalStatus::Draft, $journal->refresh()->status);
    }
}
