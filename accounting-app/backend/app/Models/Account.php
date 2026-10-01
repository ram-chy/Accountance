<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
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
