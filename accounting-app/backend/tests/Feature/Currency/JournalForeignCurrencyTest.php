<?php

namespace Tests\Feature\Currency;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Support\Money;
use App\Support\Rate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Foreign currency on the ledger itself: a manually entered journal.
 *
 * WHAT THIS FILE IS FOR
 *
 * The documents - invoices, bills, receipts - are where multi-currency is most
 * visible, but the journal is where it is most load-bearing. A document posting
 * service that hands the journal a foreign line and a base line and expects the
 * journal to convert between them is trusting two services to agree about the same
 * rate, and when they disagree the ledger is quietly wrong. So the conversion is
 * done here, once, and every other path inherits it.
 *
 * The assertions are therefore about *where the base number came from*:
 *
 *  - it is derived from the foreign amount and the rate of the journal's own date,
 *    never from the payload;
 *  - the rate is snapshotted onto the line, so the entry still describes itself
 *    after the rate table moves on;
 *  - balance is decided on the derived amounts, so a foreign entry that does not
 *    foot is refused rather than forced;
 *  - a base-currency entry is written exactly as Phase 13 wrote it.
 *
 * That last one is the regression this file exists to protect. The currency
 * columns are additive; if a plain base-currency journal ever acquires a rate of 1
 * or a stray foreign amount, every report and every trial balance in the product
 * changes meaning, and the existing 1056-test suite would still be green.
 */
class JournalForeignCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Currency $base;

    private Currency $usd;

    private Account $cash;

    private Account $revenue;

    /**
     * USD -> IDR, in effect from 2026-01-01. One for two, chosen so that a
     * conversion is unmistakable: any test that forgets to convert still produces
     * numbers that do not match, and no round number can be mistaken for the
     * identity.
     */
    private const RATE = '2.0000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUserWithRole(RoleName::Accountant);

        $this->base = Currency::factory()->code('IDR')->create();
        $this->usd = Currency::factory()->code('USD')->create();

        $this->company = $this->createCompanyFor($this->user, ['currency_id' => $this->base->getKey()]);

        $this->quoteRate('2026-01-01', self::RATE);

        [$this->cash, $this->revenue] = $this->makeCashAndRevenueAccounts($this->company);
    }

    /**
     * A USD -> IDR rate in force from the given date.
     *
     * Written with explicit currency ids rather than through the factory's `pair()`
     * helper because this fixture already owns both currencies; letting the factory
     * mint its own would collide with the unique index on currencies.code and fail
     * for a reason that has nothing to do with the behaviour under test.
     */
    private function quoteRate(string $effectiveFrom, string $rate, ?Currency $from = null): ExchangeRate
    {
        return ExchangeRate::factory()
            ->for($this->company)
            ->create([
                'from_currency_id' => ($from ?? $this->usd)->getKey(),
                'to_currency_id' => $this->base->getKey(),
                'effective_date' => $effectiveFrom,
                'rate' => $rate,
            ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function draft(array $lines, string $date = '2026-06-15'): Journal
    {
        return app(JournalService::class)->createDraft($this->company, $this->user, [
            'journal_date' => $date,
            'description' => 'Foreign cash sale',
            'lines' => $lines,
        ]);
    }

    /**
     * @return array{0: Account, 1: Account}
     */
    private function foreignPair(): array
    {
        return [
            [
                'account_id' => $this->cash->getKey(),
                'currency_id' => $this->usd->getKey(),
                'foreign_debit' => '100.0000',
            ],
            [
                'account_id' => $this->revenue->getKey(),
                'currency_id' => $this->usd->getKey(),
                'foreign_credit' => '100.0000',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Derivation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_base_amount_is_derived_from_the_foreign_amount_and_the_rate_of_the_date(): void
    {
        $journal = $this->draft($this->foreignPair());

        $debit = $journal->lines()->where('account_id', $this->cash->getKey())->firstOrFail();

        // 100.0000 USD x 2.0000000000 = 200.0000 IDR
        $this->assertSame('200.0000', $debit->debit);
        $this->assertSame('100.0000', $debit->foreign_debit);
        $this->assertSame('0.0000', $debit->credit);

        $this->assertSame($this->usd->getKey(), $debit->currency_id);
        $this->assertSame(self::RATE, $debit->exchange_rate);
    }

    #[Test]
    public function the_rate_is_snapshotted_so_the_line_survives_a_later_rate_change(): void
    {
        $journal = $this->draft($this->foreignPair(), '2026-06-15');

        /*
         * A later, very different rate for the same pair. The rate table is mutable
         * history; if the line re-read its rate from here, the same posted journal
         * would report two different base amounts on two different days.
         */
        $this->quoteRate('2026-07-01', '9.0000000000');

        $line = $journal->lines()->where('account_id', $this->cash->getKey())->firstOrFail();

        $this->assertSame('200.0000', $line->debit);
        $this->assertSame(self::RATE, $line->exchange_rate);
    }

    #[Test]
    public function the_rate_is_resolved_for_the_journals_own_date_and_not_today(): void
    {
        // A rate that exists only from 2026-09-01 onwards.
        $eur = Currency::factory()->code('EUR')->create();

        $this->quoteRate('2026-09-01', '3.0000000000', $eur);

        try {
            $this->draft([
                [
                    'account_id' => $this->cash->getKey(),
                    'currency_id' => $eur->getKey(),
                    'foreign_debit' => '10.0000',
                ],
                [
                    'account_id' => $this->revenue->getKey(),
                    'currency_id' => $eur->getKey(),
                    'foreign_credit' => '10.0000',
                ],
            ], '2026-06-15');

            $this->fail('A rate that does not exist on the journal date must not be used.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('EUR to IDR', $e->validator->errors()->first('lines.0.currency_id'));
            $this->assertStringContainsString('2026-06-15', $e->validator->errors()->first('lines.0.currency_id'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | What the payload may not claim
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_line_may_not_state_a_base_amount_alongside_a_foreign_one(): void
    {
        try {
            $this->draft([
                [
                    'account_id' => $this->cash->getKey(),
                    'currency_id' => $this->usd->getKey(),
                    'foreign_debit' => '100.0000',
                    'debit' => '999.0000',
                ],
                [
                    'account_id' => $this->revenue->getKey(),
                    'currency_id' => $this->usd->getKey(),
                    'foreign_credit' => '100.0000',
                ],
            ]);

            $this->fail('Two competing amounts for one line must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not both', $e->validator->errors()->first('lines.0.debit'));
        }

        $this->assertSame(0, Journal::query()->count());
    }

    #[Test]
    public function a_supplied_rate_must_match_the_rate_of_record(): void
    {
        [$cashLine, $revenueLine] = $this->foreignPair();

        $cashLine['exchange_rate'] = '1.0000000000';

        try {
            $this->draft([$cashLine, $revenueLine]);

            $this->fail('A rate that disagrees with the rate table must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'does not match the rate in force',
                $e->validator->errors()->first('lines.0.exchange_rate')
            );
        }
    }

    #[Test]
    public function a_supplied_rate_that_matches_is_accepted(): void
    {
        [$cashLine, $revenueLine] = $this->foreignPair();

        // Round-tripped through a JSON body, which is where a rate arrives as a
        // string with the full column width rather than a PHP float.
        $cashLine['exchange_rate'] = '2.0000000000';
        $revenueLine['exchange_rate'] = '2.00000000';

        $journal = $this->draft([$cashLine, $revenueLine]);

        $this->assertSame(
            '200.0000',
            $journal->lines()->where('account_id', $this->cash->getKey())->firstOrFail()->debit
        );
    }

    #[Test]
    public function a_rate_with_no_foreign_amount_is_refused(): void
    {
        try {
            $this->draft([
                ['account_id' => $this->cash->getKey(), 'debit' => '200.0000', 'exchange_rate' => '2.0000000000'],
                ['account_id' => $this->revenue->getKey(), 'credit' => '200.0000'],
            ]);

            $this->fail('A rate on a base-currency line is noise and must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'applies only to a foreign amount',
                $e->validator->errors()->first('lines.0.exchange_rate')
            );
        }
    }

    #[Test]
    public function a_foreign_line_must_state_its_foreign_amount(): void
    {
        try {
            $this->draft([
                ['account_id' => $this->cash->getKey(), 'currency_id' => $this->usd->getKey(), 'debit' => '200.0000'],
                ['account_id' => $this->revenue->getKey(), 'currency_id' => $this->usd->getKey(), 'credit' => '200.0000'],
            ]);

            $this->fail('A foreign line cannot fall back to the base columns.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('[USD]', $e->validator->errors()->first('lines.0.foreign_debit'));
        }
    }

    #[Test]
    public function a_foreign_line_may_not_carry_both_foreign_sides(): void
    {
        try {
            $this->draft([
                [
                    'account_id' => $this->cash->getKey(),
                    'currency_id' => $this->usd->getKey(),
                    'foreign_debit' => '100.0000',
                    'foreign_credit' => '100.0000',
                ],
                [
                    'account_id' => $this->revenue->getKey(),
                    'currency_id' => $this->usd->getKey(),
                    'foreign_credit' => '100.0000',
                ],
            ]);

            $this->fail('A two-sided foreign line must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('both a foreign debit and a foreign credit', $e->validator->errors()->first('lines.0.foreign_debit'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Balance is decided on derived amounts
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_foreign_entry_that_does_not_foot_is_refused(): void
    {
        [$cashLine, $revenueLine] = $this->foreignPair();

        $revenueLine['foreign_credit'] = '99.0000';

        try {
            $this->draft([$cashLine, $revenueLine]);

            $this->fail('An unbalanced foreign entry must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not balanced', $e->validator->errors()->first('lines'));
        }

        $this->assertSame(0, Journal::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Base currency is unchanged
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_base_currency_journal_is_written_exactly_as_before(): void
    {
        $journal = $this->draft([
            ['account_id' => $this->cash->getKey(), 'debit' => '200.0000'],
            ['account_id' => $this->revenue->getKey(), 'credit' => '200.0000'],
        ]);

        foreach ($journal->lines as $line) {
            $this->assertNull($line->currency_id);
            $this->assertNull($line->foreign_debit);
            $this->assertNull($line->foreign_credit);
            $this->assertNull($line->exchange_rate);
        }
    }

    #[Test]
    public function naming_the_companys_own_base_currency_is_normalised_to_a_base_line(): void
    {
        /*
         * The client filled in the currency field with IDR, which is this company's
         * base. Storing that as a currency with a rate of 1 would assert a quotation
         * nobody made; the stored shape has to describe what is true.
         */
        $journal = $this->draft([
            [
                'account_id' => $this->cash->getKey(),
                'currency_id' => $this->base->getKey(),
                'foreign_debit' => '200.0000',
            ],
            [
                'account_id' => $this->revenue->getKey(),
                'currency_id' => $this->base->getKey(),
                'foreign_credit' => '200.0000',
            ],
        ]);

        foreach ($journal->lines as $line) {
            $this->assertNull($line->currency_id);
            $this->assertNull($line->exchange_rate);
        }

        $this->assertSame('200.0000', $journal->lines[0]->debit);
    }

    #[Test]
    public function a_company_with_no_base_currency_can_still_book_base_amounts(): void
    {
        // A company that has never configured a base currency: every Phase 13 path
        // must keep working for it.
        $plain = Company::factory()->create();
        $plain->settings()->create();

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($plain);

        $journal = app(JournalService::class)->createDraft($plain, $this->user, [
            'journal_date' => '2026-06-15',
            'lines' => [
                ['account_id' => $cash->getKey(), 'debit' => '50.0000'],
                ['account_id' => $revenue->getKey(), 'credit' => '50.0000'],
            ],
        ]);

        $this->assertSame('50.0000', $journal->lines[0]->debit);
        $this->assertNull($journal->lines[0]->exchange_rate);
    }

    /*
    |--------------------------------------------------------------------------
    | Accounts that refuse a currency
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_account_restricted_to_another_currency_refuses_the_line(): void
    {
        $idrOnly = Account::factory()->for($this->company)->asset()->create([
            'code' => '1010',
            'name' => 'IDR Cash',
            'currency_id' => $this->base->getKey(),
        ]);

        try {
            $this->draft([
                [
                    'account_id' => $idrOnly->getKey(),
                    'currency_id' => $this->usd->getKey(),
                    'foreign_debit' => '100.0000',
                ],
                [
                    'account_id' => $this->revenue->getKey(),
                    'currency_id' => $this->usd->getKey(),
                    'foreign_credit' => '100.0000',
                ],
            ]);

            $this->fail('An account declared in another currency must refuse the line.');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('lines.0.account_id');

            $this->assertStringContainsString('IDR Cash', $message);
            $this->assertStringContainsString('USD', $message);
        }
    }

    #[Test]
    public function an_account_restricted_to_the_transaction_currency_accepts_it(): void
    {
        $usdOnly = Account::factory()->for($this->company)->asset()->create([
            'code' => '1011',
            'name' => 'USD Cash',
            'currency_id' => $this->usd->getKey(),
        ]);

        $journal = $this->draft([
            [
                'account_id' => $usdOnly->getKey(),
                'currency_id' => $this->usd->getKey(),
                'foreign_debit' => '100.0000',
            ],
            [
                'account_id' => $this->revenue->getKey(),
                'currency_id' => $this->usd->getKey(),
                'foreign_credit' => '100.0000',
            ],
        ]);

        $this->assertSame('200.0000', $journal->lines[0]->debit);
    }

    /*
    |--------------------------------------------------------------------------
    | Posting re-checks the line
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function posting_refuses_a_line_whose_account_stopped_accepting_its_currency(): void
    {
        $usdOnly = Account::factory()->for($this->company)->asset()->create([
            'code' => '1012',
            'name' => 'USD Cash',
            'currency_id' => $this->usd->getKey(),
        ]);

        $journal = $this->draft([
            [
                'account_id' => $usdOnly->getKey(),
                'currency_id' => $this->usd->getKey(),
                'foreign_debit' => '100.0000',
            ],
            [
                'account_id' => $this->revenue->getKey(),
                'currency_id' => $this->usd->getKey(),
                'foreign_credit' => '100.0000',
            ],
        ]);

        /*
         * Somebody restructures the chart of accounts after the draft was staged,
         * re-declaring the account in the base currency. The draft is still the
         * client's own work and may not be edited into something else, but it also
         * may not become part of the record: posting is the last moment the account
         * half of the currency rule can still be enforced in application code.
         */
        $usdOnly->forceFill(['currency_id' => $this->base->getKey()])->save();

        $this->makePeriodFor($this->company, '2026-06-15');

        try {
            app(JournalPostingService::class)->post($journal, $this->user);

            $this->fail('Posting must re-check currency compatibility.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('USD Cash', $e->validator->errors()->first('lines.1.account_id'));
        }

        $this->assertFalse($journal->fresh()->isPosted());
    }

    #[Test]
    public function a_foreign_journal_posts_and_keeps_its_snapshot(): void
    {
        $this->makePeriodFor($this->company, '2026-06-15');

        $journal = $this->draft($this->foreignPair());

        $posted = app(JournalPostingService::class)->post($journal, $this->user);

        $line = $posted->lines()->where('account_id', $this->cash->getKey())->firstOrFail();

        $this->assertSame('200.0000', $line->debit);
        $this->assertSame('100.0000', $line->foreignAmount()->toDatabase());
        $this->assertTrue($line->exchangeRate()->equals(Rate::of(self::RATE)));

        // The round trip the model promises for every posted foreign line.
        $this->assertTrue(
            $line->convertFromBase($line->amount())->equals(Money::of('100.0000'))
        );
    }

    #[Test]
    public function editing_a_draft_reprices_it_at_the_rate_of_the_new_date(): void
    {
        $journal = $this->draft($this->foreignPair(), '2026-06-15');

        $this->assertSame(
            '200.0000',
            $journal->lines()->where('account_id', $this->cash->getKey())->firstOrFail()->debit
        );

        $this->quoteRate('2026-08-01', '4.0000000000');

        $updated = app(JournalService::class)->updateDraft($journal, $this->company, [
            'journal_date' => '2026-09-15',
            'lines' => $this->foreignPair(),
        ]);

        $this->assertSame(
            '400.0000',
            $updated->lines()->where('account_id', $this->cash->getKey())->firstOrFail()->debit
        );
    }
}
