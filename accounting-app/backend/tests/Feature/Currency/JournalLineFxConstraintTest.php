<?php

namespace Tests\Feature\Currency;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The foreign-currency CHECK constraint on journal_lines.
 *
 * WHY THIS FILE EXISTS IN THE FORM IT DOES
 *
 * The first version of journal_lines_fx_consistency_check was written with the same
 * one-sidedness idiom the existing debit/credit check uses -
 * ((foreign_debit > 0) <> (foreign_credit > 0)) - and it silently accepted two of the
 * shapes it existed to refuse:
 *
 *   1. a line whose base amount contradicted its foreign amount and rate
 *   2. a rate on a line carrying no foreign amount at all
 *
 * The cause was SQL three-valued logic. For a foreign debit line, foreign_credit is
 * NULL, so `foreign_credit > 0` is NULL, so `(TRUE) <> (NULL)` is NULL, and a CHECK
 * constraint rejects a row only when its expression is FALSE - never when it is NULL.
 * The whole right-hand branch collapsed to NULL and a NULL constraint result is a
 * pass.
 *
 * Reading the expression does not reveal this. Running deliberately broken rows
 * against it does. That is the entire argument for this file: it is not a
 * restatement of the constraint, it is the test that a constraint nobody can read
 * aloud is still doing its job, and it would catch the next person who "simplifies"
 * the expression back to the idiomatic-looking form.
 *
 * Every case is asserted by shape, both ways. A test that only checked the good rows
 * would have passed against the broken constraint.
 */
class JournalLineFxConstraintTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Currency $usd;

    private Account $cash;

    private Journal $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUserWithRole(RoleName::Accountant);
        $this->company = $this->createCompanyFor($this->user);

        // The base currency has to exist before the company can point at it, and the
        // company needs one before any foreign-currency line is meaningful.
        $inr = Currency::factory()->create(['code' => 'INR', 'name' => 'Indian Rupee']);

        $this->company->forceFill(['currency_id' => $inr->getKey()])->save();

        $this->usd = Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar']);

        $this->cash = Account::factory()->for($this->company)->asset()->create([
            'code' => '1000',
            'name' => 'Cash',
        ]);

        $this->journal = Journal::factory()->for($this->company)->create([
            'status' => 'DRAFT',
        ]);
    }

    /**
     * Attempt a journal line exactly as given, bypassing every service.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function writeLine(array $overrides): void
    {
        JournalLine::query()->forceCreate(array_merge([
            'journal_id' => $this->journal->getKey(),
            'account_id' => $this->cash->getKey(),
            'description' => null,
            'line_number' => 1,
        ], $overrides));
    }

    /**
     * A line built from a foreign amount, the rate applied to it, and the resulting
     * base amount. Used so the accepted cases cannot pass merely because the
     * arithmetic in the fixture is self-consistent by accident.
     *
     * @return array<string, mixed>
     */
    private function foreignLine(string $foreignAmount, string $rate, string $side = 'debit'): array
    {
        $base = bcadd(
            bcmul($foreignAmount, $rate, 8),
            '0',
            4
        );

        return [
            'currency_id' => $this->usd->getKey(),
            'debit' => $side === 'debit' ? $base : '0.0000',
            'credit' => $side === 'credit' ? $base : '0.0000',
            'foreign_debit' => $side === 'debit' ? $foreignAmount : null,
            'foreign_credit' => $side === 'credit' ? $foreignAmount : null,
            'exchange_rate' => $rate,
        ];
    }

    /**
     * Shapes the database must accept.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function acceptableLines(): array
    {
        return [
            // The Phase 13 shape, unchanged: no currency, no foreign amount, no rate.
            'base currency line, exactly as Phase 13 wrote it' => [[
                'currency_id' => null,
                'debit' => '100.0000',
                'credit' => '0.0000',
                'foreign_debit' => null,
                'foreign_credit' => null,
                'exchange_rate' => null,
            ]],

            'foreign debit, rate applied' => [[
                'currency_id' => 'USD',
                'debit' => '8350.0000',
                'credit' => '0.0000',
                'foreign_debit' => '100.0000',
                'foreign_credit' => null,
                'exchange_rate' => '83.5000000000',
            ]],

            'foreign credit, rate applied' => [[
                'currency_id' => 'USD',
                'debit' => '0.0000',
                'credit' => '8350.0000',
                'foreign_debit' => null,
                'foreign_credit' => '100.0000',
                'exchange_rate' => '83.5000000000',
            ]],

            // The reason exchange rates are DECIMAL(20,10) and not the ledger's 4
            // places: at four places this rate would round to 0.0001 and convert a
            // million rupees to 100 instead of 60.
            'six-decimal rate survives the round trip' => [[
                'currency_id' => 'IDR',
                'debit' => '60.0000',
                'credit' => '0.0000',
                'foreign_debit' => '1000000.0000',
                'foreign_credit' => null,
                'exchange_rate' => '0.0000600000',
            ]],

            // A sub-unit amount that rounds down but not to nothing: 0.5 JPY at
            // 0.0061 is 0.00305, and the ledger keeps 0.0031.
            'amount rounding at the fourth decimal place' => [[
                'currency_id' => 'JPY',
                'debit' => '0.0031',
                'credit' => '0.0000',
                'foreign_debit' => '0.5000',
                'foreign_credit' => null,
                'exchange_rate' => '0.0061000000',
            ]],

            // A self-pair is legal and means rate 1: it is how a company states
            // "this currency is also my functional currency".
            'rate of exactly one' => [[
                'currency_id' => 'EUR',
                'debit' => '42.5000',
                'credit' => '0.0000',
                'foreign_debit' => '42.5000',
                'foreign_credit' => null,
                'exchange_rate' => '1.0000000000',
            ]],
        ];
    }

    #[Test]
    #[DataProvider('acceptableLines')]
    public function it_accepts_a_consistent_foreign_line(array $shape): void
    {
        // Resolve the currency code placeholder to a real id, so the provider stays
        // readable as a literal table of shapes.
        $currency = Currency::query()->firstOrCreate(
            ['code' => $shape['currency_id'] ?? 'USD'],
            ['name' => $shape['currency_id'] ?? 'USD', 'decimal_precision' => 2]
        );

        if ($shape['currency_id'] !== null) {
            $shape['currency_id'] = $currency->getKey();
        }

        $this->writeLine($shape);

        $this->assertDatabaseHas('journal_lines', [
            'journal_id' => $this->journal->getKey(),
            'debit' => $shape['debit'],
        ]);
    }

    /**
     * Shapes the database must refuse.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unacceptableLines(): array
    {
        return [
            // The two that the NULL-comparison bug let through. Both are listed
            // first because they are the regression this file exists to prevent.
            'base amount contradicts the foreign amount and rate' => [[
                'currency_id' => 'USD',
                'debit' => '8000.0000',
                'credit' => '0.0000',
                'foreign_debit' => '100.0000',
                'foreign_credit' => null,
                'exchange_rate' => '83.5000000000',
            ]],

            'credit side contradicts the foreign amount and rate' => [[
                'currency_id' => 'USD',
                'debit' => '0.0000',
                'credit' => '8000.0000',
                'foreign_debit' => null,
                'foreign_credit' => '100.0000',
                'exchange_rate' => '83.5000000000',
            ]],

            'a rate with no foreign amount to apply it to' => [[
                'currency_id' => 'USD',
                'debit' => '8350.0000',
                'credit' => '0.0000',
                'foreign_debit' => null,
                'foreign_credit' => null,
                'exchange_rate' => '83.5000000000',
            ]],

            'foreign amount with no rate' => [[
                'currency_id' => 'USD',
                'debit' => '8350.0000',
                'credit' => '0.0000',
                'foreign_debit' => '100.0000',
                'foreign_credit' => null,
                'exchange_rate' => null,
            ]],

            'rate of zero' => [[
                'currency_id' => 'USD',
                'debit' => '0.0000',
                'credit' => '0.0000',
                'foreign_debit' => '100.0000',
                'foreign_credit' => null,
                'exchange_rate' => '0.0000000000',
            ]],

            // Both foreign sides populated: the same impossible two-sided line the
            // original debit/credit check refuses.
            'foreign amount on both sides' => [[
                'currency_id' => 'USD',
                'debit' => '8350.0000',
                'credit' => '0.0000',
                'foreign_debit' => '100.0000',
                'foreign_credit' => '100.0000',
                'exchange_rate' => '83.5000000000',
            ]],

            'foreign amount that rounded away to nothing' => [[
                'currency_id' => 'USD',
                'debit' => '0.0000',
                'credit' => '0.0000',
                'foreign_debit' => '0.0000',
                'foreign_credit' => null,
                'exchange_rate' => '83.5000000000',
            ]],

            // Provenance without a currency: an amount in an unnamed currency is
            // not evidence of anything.
            'foreign amount with no currency_id' => [[
                'currency_id' => null,
                'debit' => '8350.0000',
                'credit' => '0.0000',
                'foreign_debit' => '100.0000',
                'foreign_credit' => null,
                'exchange_rate' => '83.5000000000',
            ]],

            'currency_id with no foreign amount and no rate' => [[
                'currency_id' => 'USD',
                'debit' => '8350.0000',
                'credit' => '0.0000',
                'foreign_debit' => null,
                'foreign_credit' => null,
                'exchange_rate' => null,
            ]],

            'negative foreign amount' => [[
                'currency_id' => 'USD',
                'debit' => '-8350.0000',
                'credit' => '0.0000',
                'foreign_debit' => '-100.0000',
                'foreign_credit' => null,
                'exchange_rate' => '83.5000000000',
            ]],
        ];
    }

    #[Test]
    #[DataProvider('unacceptableLines')]
    public function it_refuses_an_inconsistent_foreign_line(array $shape): void
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => $shape['currency_id'] ?? 'USD'],
            ['name' => $shape['currency_id'] ?? 'USD', 'decimal_precision' => 2]
        );

        if ($shape['currency_id'] !== null) {
            $shape['currency_id'] = $currency->getKey();
        }

        $this->assertDatabaseIntegrityViolation(fn () => $this->writeLine($shape));

        $this->assertSame(
            0,
            JournalLine::query()
                ->where('journal_id', $this->journal->getKey())
                ->count(),
            'A refused line must not be left in the table.'
        );
    }
}
