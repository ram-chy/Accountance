<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asset leaving the register.
 *
 * At most one per asset - a unique constraint, not merely a status check - so this
 * row is the permanent record of how the asset left: when, for how much, and for
 * what result.
 *
 * WHY THE ARITHMETIC HERE IS STORED WHEN THE ASSET'S IS NOT
 *
 * fixed_assets derives accumulated depreciation, because it changes every period and
 * re-deriving it is exact. These figures are different in kind:
 *
 *   carrying_value_at_disposal  is the asset's written-down value AS AT disposal
 *                                date - a historical fact about one moment. Recomputed
 *                                from today's depreciation rows it would answer a
 *                                different question, because more charges may since
 *                                have been posted, and a disposal note that silently
 *                                changed its number would be worse than one that is
 *                                merely old.
 *
 *   gain / loss                 is the COMPARISON made at that moment, and a
 *                                comparison is an event. It happened once.
 *
 * The disposal journal's debit for accumulated depreciation is exactly
 * carrying_value_at_disposal, and the credit to the asset account is exactly
 * original_cost, so what is left over is what gain or loss records - and the entry
 * balances only because both of those figures were frozen at the same time.
 *
 * NOTHING HERE IS EDITABLE. A disposal is a posted fact; the only way to change one
 * is the reversal that this application does not implement, exactly as for a posted
 * invoice. The service has no update method for this model and that is intentional.
 *
 * THERE IS NO FACTORY, for the same reason and a stronger one. A disposal row asserts
 * that a journal posted, that the asset's status moved to DISPOSED, and that its cost,
 * carrying value, proceeds, gain and loss all agree with each other and with the
 * ledger. A factory would have to invent that agreement; the table's unique constraint
 * on fixed_asset_id would let it make at most one per asset anyway. Disposals are made
 * by FixedAssetDisposalService::dispose() and by nothing else, including tests.
 */
#[Fillable([])]
class FixedAssetDisposal extends Model
{
    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * Where the proceeds were received - a cash or bank account if the buyer paid on
     * the spot, the company's receivables if not.
     */
    public function proceedsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'proceeds_account_id');
    }

    public function disposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function proceedsAmount(): Money
    {
        return Money::of($this->proceeds);
    }

    public function carryingValueAmount(): Money
    {
        return Money::of($this->carrying_value_at_disposal);
    }

    public function gainAmount(): Money
    {
        return Money::of($this->gain);
    }

    public function lossAmount(): Money
    {
        return Money::of($this->loss);
    }

    /**
     * Did this disposal make money?
     *
     * Asked of the model rather than tested as `gain > 0` at the call sites, because
     * the disposal journal needs to know whether to add a credit line for the gain or
     * a debit line for the loss, and those are different shapes of entry. A
     * zero-gain zero-loss disposal - an asset sold for exactly its written-down
     * value - is neither, and this returns false for it without special handling.
     */
    public function hasGain(): bool
    {
        return $this->gainAmount()->isPositive();
    }

    public function hasLoss(): bool
    {
        return $this->lossAmount()->isPositive();
    }

    /**
     * The single figure a disposal report needs.
     *
     * Signed: a gain is positive and a loss negative, so a column of these sums
     * without the reader having to remember which direction each column runs. The two
     * stored columns stay separate because the journal needs to know which one it is
     * dealing with; this exists for the arithmetic that does not.
     */
    public function netResultAmount(): Money
    {
        return $this->gainAmount()->minus($this->lossAmount());
    }

    /**
     * @param  Builder<FixedAssetDisposal>  $query
     * @return Builder<FixedAssetDisposal>
     */
    public function scopeInPeriod(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query
            ->whereDate('disposal_date', '>=', $from)
            ->whereDate('disposal_date', '<=', $to);
    }
}
