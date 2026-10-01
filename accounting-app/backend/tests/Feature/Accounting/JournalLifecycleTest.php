<?php

namespace Tests\Feature\Accounting;

use App\Enums\JournalStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Draft lifecycle: create, read, update, delete.
 *
 * The specification splits the journal lifecycle in two. This file covers the
 * half that is reversible; PostingTest covers the half that is not. Keeping them
 * apart is what lets "a posted journal is immutable" be asserted without
 * re-proving that drafts are editable.
 */
class JournalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function an_accountant_can_create_a_draft_journal(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'description' => 'Invoice 1001',
                'reference' => 'INV-1001',
                'lines' => [
                    ['account_id' => $cash->getKey(), 'debit' => '250.0000', 'credit' => '0'],
                    ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '250.0000'],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', JournalStatus::Draft->value)
            ->assertJsonPath('data.description', 'Invoice 1001')
            ->assertJsonPath('data.reference', 'INV-1001')
            ->assertJsonPath('data.totals.total_debit', '250.0000')
            ->assertJsonPath('data.totals.total_credit', '250.0000')
            ->assertJsonPath('data.totals.is_balanced', true)
            ->assertJsonCount(2, 'data.lines');

        $journal = Journal::findOrFail($response->json('data.id'));

        $this->assertSame($company->getKey(), $journal->company_id);
        $this->assertSame($user->getKey(), $journal->created_by);
        $this->assertNull($journal->posted_at, 'A draft must not carry a posted timestamp.');
    }

    #[Test]
    public function a_new_journal_is_numbered_sequentially_per_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $first = $this->createDraftJournal($user, $company, $cash, $revenue);
        $second = $this->createDraftJournal($user, $company, $cash, $revenue);

        $this->assertSame('JNL-000001', $first->journal_number);
        $this->assertSame('JNL-000002', $second->journal_number);
    }

    #[Test]
    public function journal_numbering_restarts_for_each_company(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cashA, $revenueA] = $this->makeCashAndRevenueAccounts($companyA);
        [$cashB, $revenueB] = $this->makeCashAndRevenueAccounts($companyB);

        $a = $this->createDraftJournal($user, $companyA, $cashA, $revenueA);
        $b = $this->createDraftJournal($user, $companyB, $cashB, $revenueB);

        // The sequence is keyed by company, so a second tenant's first journal is
        // JNL-000001 rather than a continuation of the first company's count.
        $this->assertSame('JNL-000001', $a->journal_number);
        $this->assertSame('JNL-000001', $b->journal_number);
    }

    #[Test]
    public function a_deleted_draft_does_not_release_its_journal_number(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $first = $this->createDraftJournal($user, $company, $cash, $revenue);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/journals/{$first->getKey()}")
            ->assertSuccessful();

        $second = $this->createDraftJournal($user, $company, $cash, $revenue);

        /*
         * Number 1 is burned. Reusing it would leave two different documents
         * sharing an identifier in the audit trail, and a search for JNL-000001
         * would return whichever row happened to survive.
         */
        $this->assertSame('JNL-000002', $second->journal_number);
        $this->assertDatabaseMissing('journals', ['journal_number' => 'JNL-000001']);
    }

    #[Test]
    public function an_accountant_can_read_a_journal_with_its_lines(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '75.5000');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/journals/{$journal->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $journal->getKey())
            ->assertJsonPath('data.lines.0.debit', '75.5000')
            ->assertJsonPath('data.lines.0.account_code', $cash->code)
            ->assertJsonPath('data.lines.1.credit', '75.5000')
            ->assertJsonPath('data.lines.1.account_code', $revenue->code);
    }

    #[Test]
    public function an_accountant_can_update_a_draft(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", [
                'description' => 'Corrected description',
                'lines' => [
                    ['account_id' => $cash->getKey(), 'debit' => '400.0000', 'credit' => '0'],
                    ['account_id' => $revenue->getKey(), 'debit' => '0', 'credit' => '400.0000'],
                ],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.description', 'Corrected description')
            ->assertJsonPath('data.totals.total_debit', '400.0000');

        /*
         * A line replace, not a merge. Two lines went in and two must come out:
         * a partial update would have to express "remove line 3" with no way to
         * know whether the client meant that, and a stale line left behind would
         * silently unbalance the entry the client believes they corrected.
         */
        $this->assertSame(2, $journal->lines()->count());

        foreach ($journal->lines()->get() as $line) {
            $this->assertSame('400.0000', (string) ($line->account_id === $cash->getKey()
                ? $line->debit
                : $line->credit));
        }
    }

    #[Test]
    public function updating_a_journal_does_not_change_its_number_or_date_by_accident(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", ['description' => 'Renamed only'])
            ->assertSuccessful()
            ->assertJsonPath('data.description', 'Renamed only');

        $journal->refresh();

        $this->assertSame('JNL-000001', $journal->journal_number);
        $this->assertSame('2027-01-15', $journal->journal_date->toDateString());
    }

    #[Test]
    public function a_journal_can_be_deleted_along_with_its_lines(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue);
        $lineIds = $journal->lines()->pluck('id');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/journals/{$journal->getKey()}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('journals', ['id' => $journal->getKey()]);

        // The FK cascades. Orphaned lines would keep the account's history flag
        // set forever with nothing to explain them.
        foreach ($lineIds as $lineId) {
            $this->assertDatabaseMissing('journal_lines', ['id' => $lineId]);
        }
    }

    #[Test]
    public function the_journal_listing_is_filterable_by_status_and_date(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-01-15', 'Jan 2027 A');

        $draft = $this->createDraftJournal($user, $company, $cash, $revenue, '10.00', '2027-01-15');
        $posted = $this->postJournal($user, $company, $cash, $revenue, '20.00', '2027-01-20');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/journals?status=POSTED')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $posted->getKey());

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/journals?status=DRAFT')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $draft->getKey());

        // Inclusive bounds: 2027-01-15..2027-01-15 must return the 15th, not the 20th.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/journals?from=2027-01-15&to=2027-01-15')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $draft->getKey());
    }

    #[Test]
    public function a_draft_cannot_reference_an_account_from_another_company(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($companyA);
        [, $otherRevenue] = $this->makeCashAndRevenueAccounts($companyB);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($companyA)
            ->postJson('/api/journals', [
                'journal_date' => '2027-01-15',
                'lines' => [
                    ['account_id' => $cash->getKey(), 'debit' => '10.0000', 'credit' => '0'],
                    ['account_id' => $otherRevenue->getKey(), 'debit' => '0', 'credit' => '10.0000'],
                ],
            ]);

        $response->assertStatus(422);

        $this->assertArrayHasKey(
            'lines.1.account_id',
            $this->responseErrors($response),
            'A cross-company account must be rejected per line, naming the line.'
        );

        $this->assertSame(0, Journal::query()->count());
    }

    #[Test]
    public function a_draft_may_be_dated_outside_any_accounting_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        // No period exists for 2031. Drafting ahead is legitimate: the user is
        // staging next month's entries. The period rule is enforced at posting.
        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '10.00', '2031-06-30');

        $this->assertSame('2031-06-30', $journal->journal_date->toDateString());
    }

    #[Test]
    public function a_backdated_journal_is_accepted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '10.00', '2020-01-15');

        $this->assertSame('2020-01-15', $journal->journal_date->toDateString());
    }
}
