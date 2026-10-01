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
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

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
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
