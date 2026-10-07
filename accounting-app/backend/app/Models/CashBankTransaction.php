<?php

namespace App\Models;

use App\Enums\CashBankTransactionType;
use App\Enums\PaymentStatus;
use App\Support\Money;
use App\Support\Rate;
use Database\Factories\CashBankTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deposit into, a withdrawal from, or a transfer between cash/bank accounts.
 *
 * This is an operational record, not a ledger. It says what someone asked to
 * happen and points at the journal that made it happen; it stores no balance,
 * no running total and no debit or credit figures. Those live in journal_lines
 * and are read back through LedgerService, which is why this model has no
 * balance column, no balance accessor and no relationship to anything that could
 * be mistaken for one.
 *
 * The two account columns are named for direction rather than for role, and the
 * naming is what makes the posting rule readable: money leaves source_account_id
 * and enters destination_account_id, so the journal is always
 *
 *   Dr destination_account_id
 *       Cr source_account_id
 *
 * regardless of which of the three movement types this row is. What the type
 * decides is how strict each side is about being a cash/bank account - see
 * CashBankTransactionType::requiresBothAccountsCashBank().
 *
 * transaction_type is absent from $fillable, along with company_id,
 * transaction_number, status, journal_id and the user columns. A client cannot
 * deposit into one account by submitting a row typed as a transfer, and cannot
 * nominate its own number or mark itself posted: those are set by
 * CashBankTransactionService and CashBankPostingService, or not at all.
 *
 * status reuses PaymentStatus rather than introducing a new enum. Its two cases
 * - DRAFT and POSTED - are exactly this document's lifecycle, and a second
 * status enum with the same two cases would be a second place for the meaning of
 * "posted" to be defined, which is the thing this system works hardest to avoid.
 */
#[Fillable([
    'transaction_date',
    'source_account_id',
    'destination_account_id',
    'amount',
    'reference',
    'notes',
    'currency_id',
    'exchange_rate',
])]
class CashBankTransaction extends Model
{
    /** @use HasFactory<CashBankTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'transaction_type' => CashBankTransactionType::class,
            'transaction_date' => 'date',

            /*
             * amount is deliberately NOT cast. A decimal cast would round-trip it
             * through a float, which is the one thing DECIMAL(20,4) exists to
             * prevent; the raw driver string is what has to be preserved. This is
             * the same reasoning JournalLine applies to debit and credit, and the
             * reason amountMoney() exists below.
             */
            'status' => PaymentStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The account the money left.
     */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'source_account_id');
    }

    /**
     * The account the money entered. Debited in every journal this document
     * produces.
     */
    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'destination_account_id');
    }

    /**
     * The journal this transaction produced.
     *
     * Null while the transaction is a draft. Present only after posting, which is
     * what makes the presence of this relationship a reliable proxy for "has this
     * moved money yet" without inspecting the journal itself.
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The amount as a Money value object.
     *
     * Every arithmetic path in this module goes through here rather than reading
     * the column directly, so there is exactly one place where the stored string
     * becomes a number.
     */
    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Currency (Phase 14)
    |--------------------------------------------------------------------------
    |
    | One currency for the whole transaction, and the reason there is not one per
    | leg is stated in the migration: a cross-currency bank transfer needs a rate
    | for its destination leg that is not derivable from its source, which means two
    | journal legs at two rates plus an FX reclassification - a second journal from
    | one document. CashBankPostingService refuses that case rather than converting
    | the amount at one rate and posting a journal that balances but is wrong.
    |
    | Account currency RESTRICTION applies: an account that declares a currency must
    * match this transaction's, which is what stops a EUR feed line landing in a
    * USD-denominated bank account. Accounts with no declared currency accept
    | anything, so no existing chart of accounts needs changing.
    */

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function isForeignCurrency(): bool
    {
        return $this->currency_id !== null;
    }

    /**
     * The rate snapshot, or null for a base-currency movement.
     */
    public function exchangeRate(): ?Rate
    {
        return $this->exchange_rate === null ? null : Rate::of($this->exchange_rate);
    }

    /**
     * The amount in the company's base currency - what both journal legs record.
     *
     * Prefers the stored value; falls back to converting at the current rate
     * snapshot for a draft preview. The posting path writes the converted figure
     * rather than re-deriving it.
     */
    public function baseAmount(): Money
    {
        if ($this->base_amount !== null) {
            return Money::of($this->base_amount);
        }

        $rate = $this->exchangeRate();

        return $rate === null
            ? $this->amountMoney()
            : $rate->applyTo($this->amountMoney());
    }

    /**
     * Is this account pair a cash-to-cash movement?
     *
     * Convenience for the API surface, not for the posting service - the posting
     * service asks CashBankTransactionType directly, because that is the rule it
     * is actually enforcing.
     */
    public function isInternalTransfer(): bool
    {
        return $this->transaction_type->requiresBothAccountsCashBank();
    }
}
