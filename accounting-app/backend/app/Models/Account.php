<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\CashBankKind;
use App\Enums\NormalBalance;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single line in a company's chart of accounts.
 *
 * Note the absence of a `company()`-scoped global scope. Company isolation for
 * accounting is enforced in the service and controller layer against the active
 * company, because a query scope that silently filters by "the current company"
 * makes it impossible to write an honest cross-company report or an
 * administrative task that legitimately needs two companies at once. Every
 * accounting query in this application filters explicitly.
 */
#[Fillable([
    'code',
    'name',
    'account_type',
    'cash_bank_kind',
    'normal_balance',
    'description',
    'parent_id',
    'currency_id',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | currency_id - an optional RESTRICTION, not a requirement
    |--------------------------------------------------------------------------
    |
    | The ledger is denominated in the company's base currency. `debit` and
    | `credit` on journal_lines are base amounts without exception, which is what
    | lets one set of amount columns serve the whole system. This column does not
    | change that - it states what this particular account HOLDS, so that a
    | mismatch can be refused before it becomes a misstatement.
    |
    | null - no opinion, the default for every account, and correct for revenue,
    |   expense and equity, which legitimately aggregate across currencies.
    | set  - this account holds money in this currency. A USD bank account must not
    |   be credited with a EUR receipt: the balance would become a EUR number
    |   wearing a USD label, and nothing downstream could detect it.
    |
    | Defaulting every account to the company base currency was rejected: it would
    | make every bank account silently reject every foreign receipt, including the
    | legitimate ones, and the failure would read as a puzzling validation error on
    | correct data.
    |
    | Parent and summary accounts must be left null - they roll up children of mixed
    | currencies, and restricting them would be meaningless.
    |
    | Fillable but not client-settable on an arbitrary account update: AccountCurrencyGuard
    | has to run, because the restriction has to agree with the company's base
    | currency and with the account's own type.
    */

    /**
     * `is_active` and `is_system` are absent on purpose.
     *
     * `is_system` marks an account a future module owns; a client must never be
     * able to set or clear it. `is_active` drives whether an account may be
     * posted to, and that transition goes through AccountService so it can be
     * validated and recorded - the same treatment Phase 3 gave Company::is_active.
     * Lifecycle columns are therefore written with forceFill() by the service.
     *
     * `cash_bank_kind` IS fillable, which is the opposite decision and for a
     * different reason: it is not a lifecycle flag but a classification of what
     * the account *is*, set at creation like account_type and code. It is
     * constrained to CASH or BANK (or null) at both the request layer and the
     * schema, and an account may only change it through the same validated path
     * that changes its name.
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'cash_bank_kind' => CashBankKind::class,
            'normal_balance' => NormalBalance::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'currency_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The currency this account holds, or null for no restriction.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function hasCurrencyRestriction(): bool
    {
        return $this->currency_id !== null;
    }

    /**
     * May a document in this currency post against this account?
     *
     * The permissive default is the whole point: an account with no declared
     * currency accepts anything, so an existing chart of accounts needs no changes
     * to become multi-currency-capable. An account that does declare one must match
     * exactly - "close enough" would defeat the purpose of declaring it.
     *
     * @param  int|null  $currencyId  the transaction currency, or null for base
     */
    public function acceptsCurrency(?int $currencyId): bool
    {
        if ($this->currency_id === null) {
            return true;
        }

        return $this->currency_id === $currencyId;
    }

    /**
     * Which account types may carry a currency restriction.
     *
     * Only the balances that can actually hold foreign money. A revenue account
     * denominated in USD would be meaningless - a company earns in whatever it
     * invoices in and the amount is already in base currency by the time it
     * reaches the ledger - and permitting it would let someone create an account
     * whose every posting is then questionable.
     *
     * Expense accounts are included because foreign-currency purchases are real,
     * and because a foreign-currency expense line posts to an expense account
     * regardless of how the expense was paid.
     *
     * EQUITY is deliberately excluded: FX gain and loss are income and expense
     * consequences, and a restricted equity account would be the same category
     * error.
     */
    public function accountTypeAllowsCurrencyRestriction(): bool
    {
        return in_array($this->account_type, [
            AccountType::Asset,
            AccountType::Liability,
            AccountType::Expense,
        ], true);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * The bank details for this account, if it is a bank account.
     *
     * hasOne rather than hasMany because bank_accounts.account_id is unique: an
     * account either has its bank details or it does not, and the database
     * enforces that there is never a second set.
     */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class);
    }

    /**
     * Is this account eligible to take part in a cash/bank transaction?
     *
     * The single definition of the answer, used by the eligibility endpoint, by
     * the transaction service and by the posting service, so those three cannot
     * drift into disagreeing about which accounts are cash.
     *
     * cash_bank_kind is the only criterion. It is deliberately not additionally
     * requiring an ASSET account_type, even though cash and bank are assets: that
     * would be a second rule to maintain, and one that could reject a legitimate
     * account the moment someone corrected a mistyped account_type. The kind is
     * the classification the user declared, and CashBankAccountService is where
     * setting it is validated.
     */
    public function isCashBankAccount(): bool
    {
        return $this->cash_bank_kind !== null;
    }

    /**
     * The effective normal balance: the explicit contra override when present,
     * otherwise the balance implied by the account type.
     *
     * AccountingRules is the canonical implementation of this rule; this method
     * is the model-level shortcut used when a caller already holds an Account.
     * The override column is what lets a contra account exist without any code
     * special-casing its name.
     */
    public function normalBalance(): NormalBalance
    {
        return $this->normal_balance ?? $this->account_type->normalBalance();
    }

    /**
     * True when this account's balance behaves opposite to its type.
     */
    public function isContra(): bool
    {
        return $this->normal_balance !== null
            && $this->normal_balance !== $this->account_type->normalBalance();
    }

    /**
     * Whether this account has ever been used by any journal line, draft or
     * posted.
     *
     * Used by the deletion guard. Posted-only history would allow a draft line
     * to be silently orphaned, so the check is deliberately broader.
     *
     * Prefers the value withExists('journalLines') already attached. The attribute
     * name is derived from the relation - journalLines becomes
     * journal_lines_exists - and when a query has set it, the answer is known and
     * re-querying it per row is the N+1 that withExists exists to prevent. The
     * fallback is the real query, for callers that hold a bare model such as the
     * deletion guard, which is exactly the case where no such attribute exists.
     */
    public function hasJournalHistory(): bool
    {
        if (array_key_exists('journal_lines_exists', $this->attributes)) {
            return (bool) $this->attributes['journal_lines_exists'];
        }

        return $this->journalLines()->exists();
    }
}
