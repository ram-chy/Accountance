<?php

namespace App\Models;

use App\Enums\BankReconciliationStatus;
use App\Support\Money;
use Database\Factories\BankReconciliationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bank's statement period, reconciled against the ledger.
 *
 * The architectural rule this model exists to hold is stated in one line:
 *
 *   Reconciliation records what the bank says has cleared. Accounting records
 *   what the company has posted. The two are not merged.
 *
 * Everything this table stores is therefore either (a) an external fact read off
 * a bank statement - the two statement balances, the period they cover - or (b)
 * an audit fact about who did what when. There is no column here for a ledger
 * balance, a difference, a cleared total or an uncleared total, and that absence
 * is the design rather than an omission. All of those are derived on read from
 * posted journal lines through LedgerService, so a reconciliation can never
 * disagree with the ledger: it has nothing of its own to disagree with.
 *
 * The consequence worth stating plainly: this record has no authority over the
 * accounting. Nothing in this phase writes to journals, journal lines, accounts,
 * bank accounts or cash/bank transactions. Clearing a movement here records a
 * fact about the outside world and moves no money; a reconciliation that is
 * wrong is corrected by reopening it, never by editing the ledger.
 *
 * `status` is absent from $fillable, along with company_id, account_id and the
 * audit columns. Status moves only through BankReconciliationCompletionService
 * and BankReconciliationReopenService, which each validate the transition and
 * hold the row while it happens, so there is no payload through which a client
 * can mint a completed reconciliation or move one back to DRAFT.
 *
 * No global company scope, matching every other model in this project: company
 * filtering is explicit, and the route binding for `reconciliation` is scoped to
 * the active company in AppServiceProvider.
 */
#[Fillable([
    'from_date',
    'to_date',
    'statement_opening_balance',
    'statement_closing_balance',
    'notes',
])]
class BankReconciliation extends Model
{
    /** @use HasFactory<BankReconciliationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'status' => BankReconciliationStatus::class,
            'completed_at' => 'datetime',
            'reopened_at' => 'datetime',

            /*
             * The two statement balances are deliberately NOT cast to
             * 'decimal:4'. Laravel's decimal cast hands the raw driver string
             * through, which is correct here, but the arithmetic that follows must
             * never touch that string directly - a subtraction done on the raw
             * column would be a subtraction on strings. statementOpening() and
             * statementClosing() below are the only sanctioned way in, and they
             * return Money.
             */
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The bank whose statement this reconciles.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * The ledger account being reconciled.
     *
     * Normally reachable through bankAccount, and denormalised alongside it so
     * the movement listing can filter posted journal lines without joining back
     * through bank_accounts. See the migration for why.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BankReconciliationItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who proved the statement and the ledger agreed, and when.
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Who reopened it, and when. Cleared again by the next completion, so it
     * describes the most recent reopen rather than an attempt log.
     */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function statementOpening(): Money
    {
        return Money::of($this->statement_opening_balance);
    }

    public function statementClosing(): Money
    {
        return Money::of($this->statement_closing_balance);
    }

    /**
     * May this record's contents still change?
     *
     * DRAFT and IN_PROGRESS, and nothing about it is time-dependent, so this is
     * the single definition of editability rather than a check repeated at each
     * call site.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Has this reconciliation been completed and not since reopened?
     *
     * The predicate that protects a cleared movement from being cleared again in
     * a later statement period. It is deliberately not `status === RECONCILED`:
     * a reconciliation that was reopened is back in progress, its items are
     * still attached, and its cleared lines are legitimately available to
     * reconcile again.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null && $this->reopened_at === null;
    }

    /**
     * Do this reconciliation's inclusive dates intersect another's?
     *
     * Interval intersection rather than "starts before the other ends and ends
     * after the other starts", which is the form that wrongly flags back-to-back
     * statement periods as overlapping. Same reasoning and same method as
     * AccountingPeriod::overlaps().
     */
    public function overlaps(self $other): bool
    {
        return $this->from_date->startOfDay()->lessThanOrEqualTo($other->to_date->startOfDay())
            && $this->to_date->startOfDay()->greaterThanOrEqualTo($other->from_date->startOfDay());
    }
}
