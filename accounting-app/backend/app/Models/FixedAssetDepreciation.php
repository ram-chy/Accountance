<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One posted depreciation charge for one period of one asset.
 *
 * NOT A SCHEDULE ROW. There is no pending state, no status column, and nothing here
 * that has not already been through the ledger. A row exists if and only if its
 * journal was posted, because FixedAssetService writes the two in the same
 * transaction.
 *
 * That is what makes an asset's accumulated depreciation a pure function of this
 * table: there are no unposted rows to exclude, so summing it gives exactly the
 * posted total. A model that could represent "scheduled but not yet charged" would
 * break that equality, which is why the concept is absent rather than merely unused.
 *
 * NOTHING IS FILLABLE, AND THERE IS NO FACTORY. Every column on this row is a server
 * decision: the period number and dates are derived from the asset's schedule, the
 * amount is computed, and journal_id, posted_by and posted_at are written from the
 * authenticated user after JournalPostingService has succeeded. A factory would have
 * to invent exactly those values, producing rows whose period number disagrees with
 * their dates and whose journal says something else again - fixtures that fail the
 * table's own unique constraints, or pass them while describing a charge that never
 * happened. Depreciation rows are made by
 * FixedAssetDepreciationService::depreciate() and by nothing else, including tests.
 */
#[Fillable([])]
class FixedAssetDepreciation extends Model
{
    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'period_start_date' => 'date',
            'period_end_date' => 'date',
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

    /**
     * The entry this charge produced.
     *
     * restrictOnDelete, unlike a document's journal pointer: there is no path that
     * deletes a posted journal, and if one ever existed, silently orphaning a
     * depreciation charge would leave the register claiming a write-down that the
     * ledger no longer contains.
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amountAmount(): Money
    {
        return Money::of($this->amount);
    }

    /**
     * Is this the final period of the asset's life?
     *
     * True exactly once per asset, and the property the depreciation service uses to
     * decide whether the asset becomes FULLY_DEPRECIATED. It asks the asset for its
     * period count rather than hard-coding a comparison, so the two can never
     * disagree about how long the asset's life is.
     */
    public function isFinalPeriod(): bool
    {
        return $this->period_number >= $this->fixedAsset->periodCount();
    }

    /**
     * @param  Builder<FixedAssetDepreciation>  $query
     * @return Builder<FixedAssetDepreciation>
     */
    public function scopeForAsset(Builder $query, int $fixedAssetId): Builder
    {
        return $query->where('fixed_asset_id', $fixedAssetId);
    }

    /**
     * Charges posted within a date range.
     *
     * The depreciation report's query, filtering on the period's own dates rather
     * than on posted_at: a run performed in arrears charges the periods that have
     * elapsed, and grouping those charges by when the button was pressed would put
     * last quarter's depreciation into this quarter's report.
     *
     * @param  Builder<FixedAssetDepreciation>  $query
     * @return Builder<FixedAssetDepreciation>
     */
    public function scopeInPeriod(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query
            ->whereDate('period_end_date', '>=', $from)
            ->whereDate('period_start_date', '<=', $to);
    }
}
