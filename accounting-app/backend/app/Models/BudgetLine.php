<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\BudgetLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned amount for one account in one accounting period of a budget.
 *
 * SIGN CONVENTION
 *
 * `amount` is a non-negative magnitude carried on the account's NORMAL side, and
 * that single choice is what lets a budget reconcile with the profit-and-loss
 * report without a translation table. A revenue account is credit-normal, so a
 * planned revenue of 100 000 is stored as 100000.0000 and compared directly with
 * the credit-normal P&L figure for the same period. An expense account is
 * debit-normal, so its planned 40 000 is stored as 40000.0000 and compared with
 * the debit-normal expense figure. "More revenue than planned" and "less expense
 * than planned" are then both a positive variance against a normal-side total,
 * and BudgetVarianceReportService only has to ask AccountingRules which side the
 * account lives on to know whether that is good news.
 *
 * Storing a debit/credit pair instead would force every reader to remember which
 * side a revenue account is on, which is precisely the class of error the
 * AccountingRules authority exists to remove.
 *
 * NOT PROJECTION DATA
 *
 * The amount is a plan over a period, not a balance and not a movement derived
 * from anything. `accounting_period_id` scopes it to the same windows the ledger
 * uses, so actuals for the line are exactly the posted activity the P&L would
 * report for that period.
 *
 * `budget_id` is not fillable: a line only ever exists within the budget whose
 * service created it, so the boundary between budgets is set by the server, not
 * by a request body.
 */
#[Fillable([
    'account_id',
    'accounting_period_id',
    'amount',
    'description',
])]
class BudgetLine extends Model
{
    /** @use HasFactory<BudgetLineFactory> */
    use HasFactory;

    /**
     * Deliberately NOT 'decimal:4', for the same reason as JournalLine: the
     * decimal cast hands the raw driver string through, and amount() below
     * normalises through Money so no consumer can do arithmetic on a raw string.
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'accounting_period_id' => 'integer',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    /**
     * The planned amount as an exact Money value on the account's normal side.
     */
    public function amount(): Money
    {
        return Money::of($this->amount);
    }
}
