<?php

namespace Tests\Feature\Accounting;

use App\Enums\CashBankTransactionType;
use App\Enums\JournalSource;
use App\Enums\JournalStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Posting a cash/bank transaction into the accounting record.
 *
 * Two claims are tested here, and they are the whole design.
 *
 * 1. Posting produces exactly one journal with exactly two lines, and the lines
 *    are always Dr destination / Cr source - for all three movement types. This
 *    is asserted per type rather than once, because a single assertion would not
 *    distinguish "the rule is uniform" from "the implementation happens to be
 *    right for transfers and the other two were never checked".
 *
 * 2. Once posted, the transaction is immutable. There is no route to edit or
 *    delete it, and a second post is refused. The tests for this are deliberately
 *    written against the API rather than the service, because the guarantee the
 *    user has is an HTTP-level one.
 *
 * The immutability is structural, not a status check bolted on: PUT routes to a
 * service with no posting code path and POST /post routes to a different service
 * that cannot edit. These tests are what would catch someone later collapsing the
 * two services back into one for convenience.
 */
class CashBankPostingTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    /**
     * @return array<string, Account>
     */
    private function chart(Company $company): array
    {
        return [
            'cash' => Account::factory()->for($company)->cash()->create(['code' => '1010', 'name' => 'Cash']),
            'bank' => Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Bank']),
            'expense' => Account::factory()->for($company)->expense()->create([
                'code' => '5100',
                'name' => 'Bank Charges',
            ]),
            'capital' => Account::factory()->for($company)->equity()->create([
                'code' => '3100',
                'name' => 'Share Capital',
            ]),
        ];
    }

    private function endpointFor(CashBankTransactionType $type): string
    {
        return match ($type) {
            CashBankTransactionType::Deposit => 'deposits',
            CashBankTransactionType::Withdrawal => 'withdrawals',
            CashBankTransactionType::Transfer => 'transfers',
        };
    }

    /**
     * @param  array<string, Account>  $chart
     * @return array{source: Account, destination: Account}
     */
    private function pairFor(CashBankTransactionType $type, array $chart): array
    {
        return match ($type) {
            CashBankTransactionType::Deposit => [
                'source' => $chart['capital'],
                'destination' => $chart['bank'],
            ],
            CashBankTransactionType::Withdrawal => [
                'source' => $chart['bank'],
                'destination' => $chart['expense'],
            ],
            CashBankTransactionType::Transfer => [
                'source' => $chart['bank'],
                'destination' => $chart['cash'],
            ],
        };
    }

    /**
     * Create a draft through the API and return its id.
     *
     * @param  array<string, Account>  $chart
     */
    private function draft(
        CashBankTransactionType $type,
        User $user,
        Company $company,
        array $chart,
        string $amount = '400.0000'
    ): CashBankTransaction {
        $pair = $this->pairFor($type, $chart);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/'.$this->endpointFor($type), [
                'transaction_date' => '2027-04-10',
                'amount' => $amount,
                'source_account_id' => $pair['source']->getKey(),
                'destination_account_id' => $pair['destination']->getKey(),
            ]);

        $response->assertCreated();

        return CashBankTransaction::query()
            ->whereKey($response->json('data.id'))
            ->firstOrFail();
    }

    /**
     * The core assertion, shared by the per-type tests below: posting produces
     * one journal, Dr destination / Cr source, for the stored amount.
     *
     * @param  array<string, Account>  $chart
     */
    private function assertPostingProduces(CashBankTransaction $transaction, User $actor, Company $company, array $pair): void
    {
        $this->makePeriodFor($company, '2027-04-10', 'April 2027');

        $this->actingAsJwt($actor)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'POSTED');

        $transaction->refresh();

        $this->assertSame('POSTED', $transaction->status->value);
        $this->assertNotNull($transaction->journal_id);
        $this->assertSame($actor->getKey(), $transaction->posted_by);
        $this->assertNotNull($transaction->posted_at);

        $journal = Journal::query()->whereKey($transaction->journal_id)->firstOrFail();

        $this->assertSame(JournalStatus::Posted, $journal->status);
        $this->assertSame(JournalSource::CashBankTransaction, $journal->source_type);
        $this->assertSame($transaction->getKey(), $journal->source_id);
        $this->assertSame('2027-04-10', $journal->journal_date->toDateString());

        // Exactly one journal, and exactly two lines.
        $this->assertCount(2, $journal->lines);

        $debit = $journal->lines->firstWhere('debit', '!=', '0.0000');
        $credit = $journal->lines->firstWhere('credit', '!=', '0.0000');

        $this->assertNotNull($debit, 'A journal line carrying the debit must exist.');
        $this->assertNotNull($credit, 'A journal line carrying the credit must exist.');

        // Dr destination, Cr source - the rule is the same for all three types.
        $this->assertSame(
            $pair['destination']->getKey(),
            $debit->account_id,
            'The destination account is debited.'
        );
        $this->assertSame(
            $pair['source']->getKey(),
            $credit->account_id,
            'The source account is credited.'
        );

        $this->assertSame('400.0000', $debit->debit);
        $this->assertSame('0.0000', $debit->credit);
        $this->assertSame('0.0000', $credit->debit);
        $this->assertSame('400.0000', $credit->credit);
    }

    #[Test]
    public function posting_a_deposit_debits_the_cash_bank_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Deposit, $user, $company, $chart);

        $this->assertPostingProduces(
            $transaction,
            $user,
            $company,
            $this->pairFor(CashBankTransactionType::Deposit, $chart)
        );
    }

    #[Test]
    public function posting_a_withdrawal_debits_the_named_destination(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Withdrawal, $user, $company, $chart);

        $this->assertPostingProduces(
            $transaction,
            $user,
            $company,
            $this->pairFor(CashBankTransactionType::Withdrawal, $chart)
        );
    }

    #[Test]
    public function posting_a_transfer_debits_the_destination_cash_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);

        $this->assertPostingProduces(
            $transaction,
            $user,
            $company,
            $this->pairFor(CashBankTransactionType::Transfer, $chart)
        );
    }

    /**
     * The journal's reference falls back to the document number when the user
     * supplied none, so a ledger line is always traceable back to the movement.
     */
    #[Test]
    public function the_journal_is_traceable_to_the_transaction(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);
        $this->makePeriodFor($company, '2027-04-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful();

        $journal = Journal::query()->whereKey($transaction->refresh()->journal_id)->firstOrFail();

        $this->assertStringContainsString(
            $transaction->transaction_number,
            (string) $journal->reference,
            'A posted movement must be identifiable from the ledger.'
        );
    }

    /*
     * ---------------------------------------------------------------------
     * Immutability
     * ---------------------------------------------------------------------
     */

    /**
     * A posted transaction cannot be edited. The correct way to correct one is a
     * new, reversing transaction.
     */
    #[Test]
    public function a_posted_transaction_cannot_be_edited(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);
        $this->makePeriodFor($company, '2027-04-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-transactions/{$transaction->getKey()}", [
                'amount' => '9999.0000',
                'transaction_date' => '2027-04-11',
            ])
            ->assertStatus(422);

        $transaction->refresh();

        $this->assertSame('400.0000', $transaction->amount, 'The amount must not have changed.');
        $this->assertSame('2027-04-10', $transaction->transaction_date->toDateString());
    }

    #[Test]
    public function a_posted_transaction_cannot_be_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);
        $this->makePeriodFor($company, '2027-04-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/cash-bank-transactions/{$transaction->getKey()}")
            ->assertStatus(422);

        $this->assertDatabaseHas('cash_bank_transactions', [
            'id' => $transaction->getKey(),
            'status' => 'POSTED',
        ]);
    }

    /**
     * The classic double-post: one movement, two journals, double the money.
     */
    #[Test]
    public function a_transaction_cannot_be_posted_twice(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);
        $this->makePeriodFor($company, '2027-04-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful();

        $second = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post");

        $second->assertStatus(409);

        $this->assertSame(
            1,
            Journal::query()->where('source_type', JournalSource::CashBankTransaction->value)
                ->where('source_id', $transaction->getKey())
                ->count(),
            'A refused second post must not have created a second journal.'
        );
    }

    /**
     * posted_by and posted_at come from the authenticated user and the clock,
     * never from the request body. An audit trail recording whoever the client
     * *said* posted is worthless.
     */
    #[Test]
    public function posted_by_cannot_be_supplied_by_the_client(): void
    {
        $user = $this->accountant();
        $other = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);
        $this->makePeriodFor($company, '2027-04-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post", [
                'posted_by' => $other->getKey(),
                'posted_at' => '1999-01-01T00:00:00Z',
                'status' => 'POSTED',
                'journal_id' => 4242,
            ])
            ->assertSuccessful();

        $transaction->refresh();

        $this->assertSame($user->getKey(), $transaction->posted_by);
        $this->assertNotNull($transaction->posted_at);
        $this->assertTrue($transaction->posted_at->year > 1999);
    }

    /*
     * ---------------------------------------------------------------------
     * The ledger, which is the point of posting at all
     * ---------------------------------------------------------------------
     */

    /**
     * Posting a movement changes the balance of the two accounts it touched, and
     * leaves every other account alone. This reads the balance through the Phase
     * 6 ledger endpoint rather than querying journal_lines directly, so the test
     * also confirms Phase 7's movements are visible to the existing read layer
     * without Phase 7 having added anything to it.
     */
    #[Test]
    public function posting_a_transfer_changes_only_the_two_account_balances(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);
        $this->makePeriodFor($company, '2027-04-10');

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);

        $before = $this->ledgerClosingBalance($user, $company, $chart['bank']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful();

        // Money left the bank. The balance is reported in the account's natural
        // direction, so a credit against a debit-normal asset shows as negative.
        $this->assertSame('-400.0000', $this->ledgerClosingBalance($user, $company, $chart['bank']));
        // And arrived in cash, which is also debit-normal.
        $this->assertSame('400.0000', $this->ledgerClosingBalance($user, $company, $chart['cash']));
        // The accounts not involved in the movement are untouched.
        $this->assertSame('0.0000', $this->ledgerClosingBalance($user, $company, $chart['expense']));

        $this->assertNotSame($before, $this->ledgerClosingBalance($user, $company, $chart['bank']));
    }

    /**
     * A deposit credits the offset account and debits the bank, so the balance
     * movement is signed by the account's normal balance rather than by the
     * transaction type.
     */
    #[Test]
    public function posting_a_deposit_credits_the_offset_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);
        $this->makePeriodFor($company, '2027-04-10');

        $transaction = $this->draft(CashBankTransactionType::Deposit, $user, $company, $chart);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful();

        $this->assertSame('400.0000', $this->ledgerClosingBalance($user, $company, $chart['bank']));
        /*
         * Equity was credited, and the balance is reported in each account's own
         * natural direction - equity is credit-normal, so a credit is a positive
         * movement *in* its own terms. This is why the sign of the reported
         * balance follows the account type rather than the transaction type, and
         * it is why Phase 7 has no opinion about it.
         */
        $this->assertSame('400.0000', $this->ledgerClosingBalance($user, $company, $chart['capital']));
    }

    /**
     * A withdrawal debits an expense, so the expense's debit-normal balance rises.
     */
    #[Test]
    public function posting_a_withdrawal_debits_the_named_expense(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);
        $this->makePeriodFor($company, '2027-04-10');

        $transaction = $this->draft(CashBankTransactionType::Withdrawal, $user, $company, $chart);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful();

        $this->assertSame('400.0000', $this->ledgerClosingBalance($user, $company, $chart['expense']));
        $this->assertSame('-400.0000', $this->ledgerClosingBalance($user, $company, $chart['bank']));
    }

    /*
     * ---------------------------------------------------------------------
     * Posting inherits Phase 6's guards rather than restating them
     * ---------------------------------------------------------------------
     */

    /**
     * A closed accounting period refuses the posting. CashBankPostingService does
     * not check periods - JournalPostingService does - which is the point: Phase 7
     * adds no second implementation of that rule.
     */
    #[Test]
    public function posting_into_a_closed_period_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);
        $this->makePeriodFor($company, '2027-04-10', 'April 2027', closed: true);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertStatus(422);

        $transaction->refresh();

        $this->assertSame('DRAFT', $transaction->status->value);
        $this->assertNull($transaction->journal_id, 'A refused post must not leave a journal behind.');
    }

    /**
     * The account pair is re-checked at posting time, not trusted from the draft.
     * Between saving a draft and posting it, an account may have been
     * deactivated or stripped of its cash/bank classification.
     */
    #[Test]
    public function posting_re_validates_the_account_pair(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);

        // The destination cash account is closed after the draft was saved.
        $chart['cash']->forceFill(['is_active' => false])->save();

        $this->makePeriodFor($company, '2027-04-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertStatus(422);

        $this->assertNull($transaction->refresh()->journal_id);
        $this->assertDatabaseCount('journals', 0);
    }

    /**
     * Losing the cash/bank classification after the fact is also caught.
     */
    #[Test]
    public function posting_fails_if_the_account_is_no_longer_classified(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);

        $chart['cash']->forceFill(['cash_bank_kind' => null])->save();

        $this->makePeriodFor($company, '2027-04-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertStatus(422);

        $this->assertDatabaseCount('journals', 0);
    }

    /**
     * A classification cannot be removed from an account that already has
     * accounting history, which is the mirror image of the check above: once a
     * posted journal refers to the account as cash, the label has to stay.
     */
    #[Test]
    public function a_classification_cannot_be_cleared_once_the_account_has_history(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);
        $this->makePeriodFor($company, '2027-04-10');

        $transaction = $this->draft(CashBankTransactionType::Transfer, $user, $company, $chart);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertSuccessful();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$chart['cash']->getKey()}", [
                'cash_bank_kind' => null,
            ]);

        $response->assertStatus(422);
        $this->assertSame('CASH', $chart['cash']->refresh()->cash_bank_kind->value);
    }

    /**
     * Read an account's balance through the Phase 4 balance endpoint.
     *
     * Deliberately not a direct sum over journal_lines, and deliberately not a
     * Phase 7 endpoint either: there isn't one. Phase 7 stores no balance, so the
     * existing ledger read layer is the only place the question can be asked -
     * which is exactly the claim these tests exist to check.
     */
    private function ledgerClosingBalance(User $user, Company $company, Account $account): string
    {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$account->getKey()}/balance");

        $response->assertSuccessful();

        return (string) $response->json('data.balance');
    }
}
