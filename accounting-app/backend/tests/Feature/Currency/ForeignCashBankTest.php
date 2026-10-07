<?php

namespace Tests\Feature\Currency;

use App\Enums\CashBankTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\CashBank\CashBankPostingService;
use App\Services\Accounting\CashBank\CashBankTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cash and bank movements in a foreign currency.
 *
 * WHY THIS IS THE SIMPLEST FX CASE IN THE APPLICATION
 *
 * Every other foreign document in this phase is hard because two rates meet: an
 * invoice is carried at one rate and the money that settles it arrives at another,
 * and the gap has to become an explicit gain or loss. A cash/bank movement has no
 * such gap. Both of its legs are the same money on the same day, so the foreign
 * amount converts once and lands identically on both sides.
 *
 * That means there is no FX line to write and no gain to compute, and the correct
 * implementation is the boring one. The tests below exist mostly to prove the
 * boring answer was chosen on purpose and not skipped:
 *
 *  1. A foreign movement books two lines in the transaction currency, and the base
 *     figures the journal derives from them are equal. An implementation that
 *     posted one FX line here would post a gain the company did not have.
 *
 *  2. The rate is the one in force on the transaction's own date, so a backdated
 *     feed line prices at the backdated rate. This is the one place the wrong
 *     answer is plausible, because "the latest rate" is what a naive
 *     implementation gets by default.
 *
 *  3. An account that declares a currency cannot be used with another one, which
 *     is what stops a EUR bank feed line landing in a USD-denominated account.
 *
 *  4. A cross-currency transfer is refused, not half-supported.
 *
 *  5. A base-currency movement is byte-for-byte what it always was.
 */
class ForeignCashBankTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Currency $base;

    private Currency $usd;

    private Currency $eur;

    /** @var array<string, Account> */
    private array $accounts;

    private Account $usdBank;

    private Account $eurBank;

    private Account $plainBank;

    private Account $capital;

    private Account $expense;

    /** The rate that stood in June. */
    private const JUNE_RATE = '16.0000000000';

    /** The rate that stood from July on. */
    private const JULY_RATE = '17.0000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUserWithRole(RoleName::Accountant);

        $this->base = Currency::factory()->code('IDR')->create();
        $this->usd = Currency::factory()->code('USD')->create();
        $this->eur = Currency::factory()->code('EUR')->create();

        $this->company = $this->createCompanyFor($this->user, ['currency_id' => $this->base->getKey()]);

        $this->usdBank = Account::factory()->for($this->company)->bank()->create([
            'code' => '1020', 'name' => 'USD Bank', 'currency_id' => $this->usd->getKey(),
        ]);

        $this->eurBank = Account::factory()->for($this->company)->bank()->create([
            'code' => '1021', 'name' => 'EUR Bank', 'currency_id' => $this->eur->getKey(),
        ]);

        $this->plainBank = Account::factory()->for($this->company)->bank()->create([
            'code' => '1030', 'name' => 'Local Bank',
        ]);

        $this->capital = Account::factory()->for($this->company)->equity()->create([
            'code' => '3100', 'name' => 'Share Capital',
        ]);

        $this->expense = Account::factory()->for($this->company)->expense()->create([
            'code' => '5100', 'name' => 'Bank Charges',
        ]);

        $this->accounts = [
            'usd_bank' => $this->usdBank,
            'eur_bank' => $this->eurBank,
            'plain_bank' => $this->plainBank,
            'capital' => $this->capital,
            'expense' => $this->expense,
        ];

        $this->makePeriodFor($this->company, '2026-06-15', 'Jun 2026');
        $this->makePeriodFor($this->company, '2026-07-15', 'Jul 2026');

        $this->quoteRate('2026-06-01', self::JUNE_RATE);
        $this->quoteRate('2026-07-01', self::JULY_RATE);
    }

    private function quoteRate(string $effectiveFrom, string $rate, ?Currency $from = null): ExchangeRate
    {
        return ExchangeRate::factory()->for($this->company)->create([
            'from_currency_id' => ($from ?? $this->usd)->getKey(),
            'to_currency_id' => $this->base->getKey(),
            'effective_date' => $effectiveFrom,
            'rate' => $rate,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(CashBankTransactionType $type, array $overrides = []): CashBankTransaction
    {
        return app(CashBankTransactionService::class)->createDraft(
            $this->company,
            $this->user,
            $type,
            array_merge([
                'transaction_date' => '2026-07-15',
                'source_account_id' => $this->capital->getKey(),
                'destination_account_id' => $this->usdBank->getKey(),
                'amount' => '1000.0000',
                'currency_id' => $this->usd->getKey(),
            ], $overrides),
        );
    }

    private function postMovement(CashBankTransaction $transaction): CashBankTransaction
    {
        return app(CashBankPostingService::class)->post($transaction, $this->user);
    }

    private function linesOf(CashBankTransaction $transaction)
    {
        return Journal::query()
            ->whereKey($transaction->journal_id)
            ->firstOrFail()
            ->lines()
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Snapshot
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_foreign_deposit_snapshots_its_currency_and_rate_on_the_draft(): void
    {
        $transaction = $this->draft(CashBankTransactionType::Deposit);

        $this->assertSame($this->usd->getKey(), $transaction->currency_id);
        $this->assertTrue($transaction->isForeignCurrency());
        $this->assertSame(self::JULY_RATE, (string) $transaction->exchange_rate);
    }

    #[Test]
    public function a_draft_with_no_currency_is_base_currency_at_an_implicit_rate_of_one(): void
    {
        $transaction = $this->draft(CashBankTransactionType::Deposit, [
            'currency_id' => null,
            'destination_account_id' => $this->plainBank->getKey(),
        ]);

        $this->assertNull($transaction->currency_id);
        $this->assertNull($transaction->exchange_rate);
        $this->assertFalse($transaction->isForeignCurrency());
    }

    /*
    |--------------------------------------------------------------------------
    | Backdated rate
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_backdated_movement_is_priced_at_the_rate_that_stood_on_its_own_date(): void
    {
        /*
         * Dated June, so the 16,000 rate, even though 17,000 was the rate on the day
         * the draft was written. The brief is explicit that a backdated movement must
         * not take today's rate, and this is the test that would catch it.
         */
        $transaction = $this->draft(CashBankTransactionType::Deposit, [
            'transaction_date' => '2026-06-20',
        ]);

        $this->assertSame(self::JUNE_RATE, (string) $transaction->exchange_rate);
    }

    #[Test]
    public function posting_a_backdated_movement_books_the_rate_of_its_date_not_the_latest(): void
    {
        $posted = $this->postMovement($this->draft(CashBankTransactionType::Deposit, [
            'transaction_date' => '2026-06-20',
        ]));

        $this->assertSame('16000.0000', $posted->base_amount);
        $this->assertSame(self::JUNE_RATE, (string) $posted->exchange_rate);
    }

    #[Test]
    public function re_dating_a_draft_re_prices_it(): void
    {
        $draft = $this->draft(CashBankTransactionType::Deposit, [
            'transaction_date' => '2026-06-20',
        ]);

        $moved = app(CashBankTransactionService::class)->updateDraft(
            $draft,
            $this->company,
            CashBankTransactionType::Deposit,
            ['transaction_date' => '2026-07-20'],
        );

        $this->assertSame(self::JULY_RATE, (string) $moved->exchange_rate);
    }

    #[Test]
    public function a_movement_in_a_currency_with_no_rate_cannot_be_drafted(): void
    {
        $this->expectException(ValidationException::class);

        $this->draft(CashBankTransactionType::Deposit, ['currency_id' => $this->eur->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | The entry
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_foreign_deposit_books_two_foreign_lines_and_equal_base_figures(): void
    {
        $posted = $this->postMovement($this->draft(CashBankTransactionType::Deposit));

        $lines = $this->linesOf($posted);

        $this->assertCount(2, $lines);

        $debit = $lines->firstWhere('account_id', $this->usdBank->getKey());
        $credit = $lines->firstWhere('account_id', $this->capital->getKey());

        // The money itself, in the currency it was actually received in.
        $this->assertSame('1000.0000', $debit->foreign_debit);
        $this->assertNull($debit->foreign_credit);
        $this->assertNull($credit->foreign_debit);
        $this->assertSame('1000.0000', $credit->foreign_credit);

        // Derived by the journal, identical on both sides.
        $this->assertSame('17000.0000', $debit->debit);
        $this->assertSame('17000.0000', $credit->credit);

        foreach ($lines as $line) {
            $this->assertSame($this->usd->getKey(), $line->currency_id);
            $this->assertSame(self::JULY_RATE, (string) $line->exchange_rate);
        }
    }

    #[Test]
    public function a_foreign_movement_books_no_exchange_gain_or_loss(): void
    {
        /*
         * Nothing converts here: the same foreign money is in before and out, so
         * there is no difference to book. A third line on this entry would be an FX
         * gain of zero or, worse, a real one manufactured out of a movement that
         * never converted anything.
         */
        $posted = $this->postMovement($this->draft(CashBankTransactionType::Deposit));

        $this->assertCount(2, $this->linesOf($posted));
    }

    #[Test]
    public function the_stored_base_amount_is_the_figure_the_journal_booked(): void
    {
        $posted = $this->postMovement($this->draft(CashBankTransactionType::Deposit));

        $debit = $this->linesOf($posted)->firstWhere('account_id', $this->usdBank->getKey());

        $this->assertSame($debit->debit, $posted->base_amount);
        $this->assertSame('17000.0000', $posted->baseAmount()->toDatabase());
    }

    #[Test]
    public function a_foreign_withdrawal_debits_the_destination_and_credits_the_bank(): void
    {
        $draft = $this->draft(CashBankTransactionType::Withdrawal, [
            'source_account_id' => $this->usdBank->getKey(),
            'destination_account_id' => $this->expense->getKey(),
        ]);

        $lines = $this->linesOf($this->postMovement($draft));

        $this->assertSame('1000.0000', $lines->firstWhere('account_id', $this->expense->getKey())->foreign_debit);
        $this->assertSame('1000.0000', $lines->firstWhere('account_id', $this->usdBank->getKey())->foreign_credit);
    }

    #[Test]
    public function a_foreign_transfer_between_two_accounts_of_one_currency_books_both_legs_in_it(): void
    {
        $second = Account::factory()->for($this->company)->bank()->create([
            'code' => '1022', 'name' => 'USD Bank Two', 'currency_id' => $this->usd->getKey(),
        ]);

        $draft = $this->draft(CashBankTransactionType::Transfer, [
            'source_account_id' => $this->usdBank->getKey(),
            'destination_account_id' => $second->getKey(),
        ]);

        $lines = $this->linesOf($this->postMovement($draft));

        $this->assertSame('1000.0000', $lines->firstWhere('account_id', $second->getKey())->foreign_debit);
        $this->assertSame('1000.0000', $lines->firstWhere('account_id', $this->usdBank->getKey())->foreign_credit);
    }

    /*
    |--------------------------------------------------------------------------
    | Account currency restriction
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_foreign_movement_cannot_land_in_an_account_that_holds_another_currency(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('holds [EUR]');

        $this->draft(CashBankTransactionType::Deposit, [
            'destination_account_id' => $this->eurBank->getKey(),
        ]);
    }

    #[Test]
    public function an_account_with_no_declared_currency_accepts_any(): void
    {
        $draft = $this->draft(CashBankTransactionType::Deposit, [
            'destination_account_id' => $this->plainBank->getKey(),
        ]);

        $this->assertSame($this->usd->getKey(), $draft->currency_id);
    }

    #[Test]
    public function the_offset_account_is_held_to_the_same_currency(): void
    {
        $declared = Account::factory()->for($this->company)->equity()->create([
            'code' => '3200', 'name' => 'EUR Capital', 'currency_id' => $this->eur->getKey(),
        ]);

        try {
            $this->draft(CashBankTransactionType::Deposit, [
                'source_account_id' => $declared->getKey(),
            ]);

            $this->fail('A USD movement should not credit an account declared in EUR.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source_account_id', $e->errors());
        }
    }

    #[Test]
    public function changing_one_side_of_a_draft_cannot_produce_an_impossible_pair(): void
    {
        /*
         * The draft is valid: capital into a USD bank. The update then swaps the
         * bank for a EUR one. Re-resolving only the changed field would leave a
         * currency-restriction failure unnoticed until posting.
         */
        $draft = $this->draft(CashBankTransactionType::Deposit);

        $this->expectException(ValidationException::class);

        app(CashBankTransactionService::class)->updateDraft(
            $draft,
            $this->company,
            CashBankTransactionType::Deposit,
            ['destination_account_id' => $this->eurBank->getKey()],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-currency transfer, declined
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_transfer_between_two_different_declared_currencies_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('two separate movements');

        $this->draft(CashBankTransactionType::Transfer, [
            'source_account_id' => $this->usdBank->getKey(),
            'destination_account_id' => $this->eurBank->getKey(),
        ]);
    }

    #[Test]
    public function a_refused_cross_currency_transfer_books_nothing_even_if_it_reaches_posting(): void
    {
        /*
         * The draft is built through the model to get past the service's own check,
         * because what is under test here is the posting guard. A document that
         * reaches a posting service already invalid is exactly the case where the
         * second line of defence has to hold on its own.
         */
        $draft = $this->draft(CashBankTransactionType::Transfer, [
            'source_account_id' => $this->usdBank->getKey(),
            'destination_account_id' => $this->plainBank->getKey(),
        ]);

        $draft->forceFill([
            'destination_account_id' => $this->eurBank->getKey(),
        ])->save();

        try {
            $this->postMovement($draft);

            $this->fail('A transfer between a USD account and a EUR account should not post.');
        } catch (ValidationException $e) {
            $this->assertCount(0, Journal::query()->get());
            $this->assertSame(PaymentStatus::Draft, $draft->fresh()->status);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Base currency, unchanged
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_base_currency_movement_books_exactly_what_it_always_did(): void
    {
        $posted = $this->postMovement($this->draft(CashBankTransactionType::Deposit, [
            'currency_id' => null,
            'destination_account_id' => $this->plainBank->getKey(),
        ]));

        $lines = $this->linesOf($posted);

        $debit = $lines->firstWhere('account_id', $this->plainBank->getKey());

        $this->assertCount(2, $lines);
        $this->assertSame('1000.0000', $debit->debit);
        $this->assertSame('1000.0000', $posted->base_amount);

        foreach ($lines as $line) {
            $this->assertNull($line->currency_id);
            $this->assertNull($line->foreign_debit);
            $this->assertNull($line->foreign_credit);
            $this->assertNull($line->exchange_rate);
        }
    }

    #[Test]
    public function naming_the_base_currency_explicitly_is_the_same_as_naming_none(): void
    {
        $posted = $this->postMovement($this->draft(CashBankTransactionType::Deposit, [
            'currency_id' => $this->base->getKey(),
            'destination_account_id' => $this->plainBank->getKey(),
        ]));

        $this->assertNull($posted->currency_id);
        $this->assertNull($posted->exchange_rate);
        $this->assertSame('1000.0000', $posted->base_amount);

        foreach ($this->linesOf($posted) as $line) {
            $this->assertNull($line->currency_id);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Isolation and immutability
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_company_cannot_move_money_through_another_companys_account(): void
    {
        $other = $this->createCompanyFor($this->user, ['currency_id' => $this->base->getKey()]);

        $theirs = Account::factory()->for($other)->bank()->create([
            'code' => '1020', 'name' => 'Their USD Bank', 'currency_id' => $this->usd->getKey(),
        ]);

        $this->expectException(ValidationException::class);

        $this->draft(CashBankTransactionType::Deposit, [
            'destination_account_id' => $theirs->getKey(),
        ]);
    }

    #[Test]
    public function a_posted_movement_keeps_the_rate_it_was_posted_at(): void
    {
        $posted = $this->postMovement($this->draft(CashBankTransactionType::Deposit));

        $this->quoteRate('2026-08-01', '18.0000000000');

        $this->assertSame(self::JULY_RATE, (string) $posted->fresh()->exchange_rate);
        $this->assertSame('17000.0000', $posted->fresh()->base_amount);
    }
}
