<?php

namespace App\Models;

use App\Support\Money;
use App\Support\Rate;
use Database\Factories\JournalLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of one account within a journal.
 *
 * Exactly one of debit/credit carries a value and the other is zero. That shape
 * is enforced three times over: the one_sided CHECK constraint in the database,
 * the validation rules on the journal lines request, and the assertions in
 * JournalService. Three layers look redundant until you consider which ones a
 * future code path will actually use - the database one is the only one that a
 * hand-written INSERT cannot get past.
 *
 * Phase 14 added currency_id, foreign_debit, foreign_credit and exchange_rate to
 * this table WITHOUT changing the meaning of debit or credit. They remain base
 * currency amounts; the additions record which currency the entry was transacted
 * in and what it was worth there. See the foreign-currency section below and the
 * migration for why there is deliberately no second base_debit/base_credit pair.
 */
#[Fillable([
    'account_id',
    'description',
    'debit',
    'credit',
    'line_number',
    'currency_id',
    'foreign_debit',
    'foreign_credit',
    'exchange_rate',
])]
class JournalLine extends Model
{
    /** @use HasFactory<JournalLineFactory> */
    use HasFactory;

    /**
     * Deliberately NOT 'decimal:4'. Laravel's decimal cast returns a string, but
     * it hands the raw driver string straight through, and a value that has been
     * through a JSON response and back can arrive with a different scale. The
     * amount accessors below normalise through Money instead, so no consumer can
     * accidentally do arithmetic on a raw string.
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function debitAmount(): Money
    {
        return Money::of($this->debit);
    }

    public function creditAmount(): Money
    {
        return Money::of($this->credit);
    }

    public function isDebit(): bool
    {
        return $this->debitAmount()->isPositive();
    }

    public function isCredit(): bool
    {
        return $this->creditAmount()->isPositive();
    }

    /**
     * The single amount on this line, whichever side carries it.
     *
     * ALWAYS A BASE AMOUNT. `debit` and `credit` are the company's functional
     * currency and nothing else; there is no report, balance or control check in
     * this system that may read a foreign figure as though it were a base one.
     */
    public function amount(): Money
    {
        return $this->isDebit() ? $this->debitAmount() : $this->creditAmount();
    }

    /*
    |--------------------------------------------------------------------------
    | Foreign currency (Phase 14)
    |--------------------------------------------------------------------------
    |
    | Provenance for the base amounts above, not a second set of them. A line is in
    | one of exactly two states:
    |
    |   base currency    currency_id, foreign_* and exchange_rate all null
    |   foreign currency currency_id set, one foreign_* set, exchange_rate set,
    |                    and foreign * rate == the base amount exactly
    |
    | The database CHECK on journal_lines enforces the second rule, including the
    | arithmetic, so these accessors are a convenience for reading rather than the
    | guarantee - a line that reaches the database inconsistent with its own rate
    | cannot exist.
    */

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Was this line entered in a currency other than the company's base?
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_id !== null;
    }

    /**
     * The amount in the transaction currency, or null for a base-currency line.
     *
     * Null and 0.0000 are not interchangeable here: a posted line always has a
     * positive amount, so a foreign amount can never legitimately be zero, and the
     * column being null is how "no foreign amount exists" is expressed.
     */
    public function foreignAmount(): ?Money
    {
        if ($this->foreign_debit === null && $this->foreign_credit === null) {
            return null;
        }

        return Money::of($this->foreign_debit ?? $this->foreign_credit);
    }

    public function exchangeRate(): ?Rate
    {
        return $this->exchange_rate === null ? null : Rate::of($this->exchange_rate);
    }

    /**
     * The transaction currency's code, or null for a base-currency line.
     *
     * Returns a code rather than a Currency model deliberately: a report iterating
     | thousands of ledger rows must not trigger a currency lookup per row. The
     * code is on the rate snapshot's own row scope and is enough to label a
     * column; anything needing the full record joins deliberately.
     */
    public function foreignCurrencyCode(): ?string
    {
        return $this->currency?->code;
    }

    /**
     * Convert this line's base amount back into the transaction currency.
     *
     * For a foreign line this is the exact inverse of the conversion that produced
     * it, so `foreignAmount() == convertFromBase(amount())` holds for every posted
     * line. That is a property to assert in tests, not a routine to rely on: it
     * can only be exactly true while the rate is unchanged, and the point of the
     * stored foreign amount is that it does not need re-deriving.
     *
     * For a base-currency line this is the identity, which is what lets a report
     * call it unconditionally instead of branching on the currency.
     */
    public function convertFromBase(Money $amount): Money
    {
        $rate = $this->exchangeRate();

        return $rate === null ? $amount : Money::product($amount->toDatabase(), $rate->reciprocal()->toDatabase());
    }
}
