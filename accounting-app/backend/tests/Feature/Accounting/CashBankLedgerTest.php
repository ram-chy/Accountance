<?php

namespace Tests\Feature\Accounting;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The read side of cash and bank.
 *
 * Phase 7 deliberately adds no read endpoint of its own. There is no
 * /cash-bank-transactions/balance, no per-account running total and no
 * reconciliation view, because every one of those is a question about posted
 * journal lines and LedgerService already answers it for any account. A second
 * implementation of the same arithmetic would not be a convenience - it would be
 * a second number to keep in agreement with the first.
 *
 * So this file tests the *absence* as much as the presence:
 *
 *   - Posted movements appear in the existing Phase 6 ledger, unchanged, and
 *     drafts do not.
 *   - The existing cash-bank report works over an account that Phase 7
 *     classified, without Phase 7 having touched it.
 *   - No Phase 7 route exposes a balance field.
 *
 * The last one is worth stating plainly: if someone later adds a balance to
 * CashBankTransactionResource "just for convenience", the ledger and the resource
 * become two answers to the same question and this test is what notices.
 */
class CashBankLedgerTest extends TestCase
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
            'revenue' => Account::factory()->for($company)->revenue()->create([
                'code' => '4000',
                'name' => 'Sales Revenue',
            ]),
        ];
    }

    private function createTransfer(User $user, Company $company, array $accounts, string $amount): string
    {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/transfers', [
                'transaction_date' => '2027-07-10',
                'amount' => $amount,
                'reference' => 'TRF-'.$amount,
                'source_account_id' => $accounts['bank']->getKey(),
                'destination_account_id' => $accounts['cash']->getKey(),
            ]);

        $response->assertCreated();

        return (string) $response->json('data.id');
    }

    private function postTransaction(User $user, Company $company, string $id): void
    {
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$id}/post")
            ->assertSuccessful();
    }

    /**
     * A draft moves nothing. A posted one moves exactly its amount.
     *
     * The pair of assertions is the point: reporting a draft's effect would make
     * the ledger disagree with the accounts, and would mean a balance that
     * depends on when the user got round to posting.
     */
    #[Test]
    public function drafts_are_invisible_to_the_ledger_and_postings_are_not(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->chart($company);
        $this->makePeriodFor($company, '2027-07-10', 'July 2027');

        $draftId = $this->createTransfer($user, $company, $accounts, '300.0000');

        $this->assertSame('0.0000', $this->balance($user, $company, $accounts['bank']));
        $this->assertSame('0.0000', $this->balance($user, $company, $accounts['cash']));

        $this->postTransaction($user, $company, $draftId);

        $this->assertSame('-300.0000', $this->balance($user, $company, $accounts['bank']));
        $this->assertSame('300.0000', $this->balance($user, $company, $accounts['cash']));
    }

    /**
     * Several posted movements accumulate correctly - the running arithmetic is
     * the existing one, but a phase that writes journals badly could still break
     * it, so the accumulation is asserted rather than assumed.
     */
    #[Test]
    public function posted_movements_accumulate(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->chart($company);
        $this->makePeriodFor($company, '2027-07-10', 'July 2027');

        foreach (['100.0000', '250.5000', '49.5000'] as $amount) {
            $this->postTransaction($user, $company, $this->createTransfer($user, $company, $accounts, $amount));
        }

        // 100 + 250.50 + 49.50
        $this->assertSame('400.0000', $this->balance($user, $company, $accounts['cash']));
        $this->assertSame('-400.0000', $this->balance($user, $company, $accounts['bank']));
    }

    /**
     * The existing cash-bank report works over an account Phase 7 classified,
     * with no Phase 7 code involved in producing it.
     */
    #[Test]
    public function the_existing_cash_bank_report_reads_phase_seven_movements(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->chart($company);
        $this->makePeriodFor($company, '2027-07-10', 'July 2027');

        $this->postTransaction($user, $company, $this->createTransfer($user, $company, $accounts, '125.0000'));

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/reports/cash-bank?account_id={$accounts['bank']->getKey()}");

        /*
         * The shape is Phase 6's, asserted against its real contract:
         * data.account.id, and period_debits / period_credits / closing_balance.
         * A cash/bank transfer credits the bank, so the credit total is the amount
         * and the closing balance is negative - the account is in overdraft, which
         * is what moving money out of it means.
         */
        $response->assertSuccessful()
            ->assertJsonPath('data.account.id', $accounts['bank']->getKey())
            ->assertJsonPath('data.opening_balance', '0.0000')
            ->assertJsonPath('data.period_debits', '0.0000')
            ->assertJsonPath('data.period_credits', '125.0000')
            ->assertJsonPath('data.closing_balance', '-125.0000');
    }

    /**
     * The transaction resource exposes no financial figure beyond the amount the
     * user entered.
     *
     * A balance on this resource would be a second answer to a question the
     * ledger already answers, computed at a different moment from a different
     * query - which is precisely how two numbers come to disagree.
     */
    #[Test]
    public function the_transaction_resource_carries_no_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->chart($company);
        $this->makePeriodFor($company, '2027-07-10', 'July 2027');

        $id = $this->createTransfer($user, $company, $accounts, '500.0000');
        $this->postTransaction($user, $company, $id);

        foreach (["/api/cash-bank-transactions/{$id}", '/api/cash-bank-transactions'] as $uri) {
            $response = $this->actingAsJwt($user)
                ->withCompanyContext($company)
                ->getJson($uri);

            $response->assertSuccessful();

            $row = $uri === '/api/cash-bank-transactions'
                ? collect($response->json('data'))->firstWhere('id', (int) $id)
                : $response->json('data');

            $this->assertIsArray($row);

            foreach ([
                'balance', 'closing_balance', 'opening_balance', 'current_balance',
                'running_balance', 'total_debit', 'total_credit',
            ] as $forbidden) {
                $this->assertArrayNotHasKey(
                    $forbidden,
                    $row,
                    "The transaction resource must not expose [{$forbidden}]."
                );
            }
        }
    }

    /**
     * The listing filters by type, by status and by date.
     *
     * Status is the interesting one: filtering to DRAFT is how a user finds the
     * movements still awaiting posting, so it has to work.
     */
    #[Test]
    public function the_listing_can_be_filtered(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->chart($company);
        $this->makePeriodFor($company, '2027-07-10', 'July 2027');

        $posted = $this->createTransfer($user, $company, $accounts, '100.0000');
        $this->postTransaction($user, $company, $posted);

        $draft = $this->createTransfer($user, $company, $accounts, '200.0000');

        $byStatus = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-transactions?status=DRAFT');

        $byStatus->assertSuccessful();
        $this->assertSame(
            [(int) $draft],
            collect($byStatus->json('data'))->pluck('id')->all()
        );

        $byType = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-transactions?transaction_type=TRANSFER');

        $byType->assertSuccessful();
        $this->assertCount(2, $byType->json('data'));

        $byAccount = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/cash-bank-transactions?account_id={$accounts['cash']->getKey()}");

        $byAccount->assertSuccessful();
        $this->assertCount(
            2,
            $byAccount->json('data'),
            'The account filter matches the destination side as well as the source.'
        );

        $byDate = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-transactions?from=2027-07-11');

        $byDate->assertSuccessful();
        $this->assertCount(0, $byDate->json('data'));
    }

    /**
     * The listing is paginated, and the page size is capped so the endpoint
     * cannot be asked to hold a company's entire history in memory.
     */
    #[Test]
    public function the_listing_is_paginated_and_capped(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->chart($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-transactions?per_page=5000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    /**
     * The most recent movement is listed first, which is the order anyone
     * reconciling a bank statement wants.
     */
    #[Test]
    public function the_listing_is_newest_first(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->chart($company);

        $first = $this->createTransfer($user, $company, $accounts, '10.0000');
        $second = $this->createTransfer($user, $company, $accounts, '20.0000');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-transactions');

        $response->assertSuccessful();

        $this->assertSame(
            [(int) $second, (int) $first],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    private function balance(User $user, Company $company, Account $account): string
    {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/accounts/{$account->getKey()}/balance");

        $response->assertSuccessful();

        return (string) $response->json('data.balance');
    }
}
