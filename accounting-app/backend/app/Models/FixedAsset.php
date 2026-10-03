<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetAcquisitionMethod;
use App\Enums\FixedAssetStatus;
use App\Support\Money;
use Database\Factories\FixedAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One asset the company owns, from acquisition to disposal.
 *
 * THE DERIVED FIGURES ON THIS MODEL ARE DERIVED, NOT STORED
 *
 * This row carries no accumulated_depreciation and no carrying_value column, and
 * that absence is the design. Both are computable from fixed_asset_depreciations -
 * a row of which exists if and only if its charge has been posted, because the two
 * are written in one transaction - so storing either would be a second source for a
 * figure the schema can already answer exactly, and a second source is a second
 * chance for the register and the ledger to disagree with nothing noticing.
 *
 * The accessors below therefore query, and the two callers that run per request
 * (the schedule endpoint and the disposal service) are the only places they are
 * called.
 *
 * WHAT IS FILLABLE IS WHAT A USER ACTUALLY SUPPLIES
 *
 * Notice that none of the five category-sourced account columns are fillable. A
 * user fills in a category, a cost and a date; the accounts come from the category,
 * because allowing them to be supplied independently would permit an asset whose
 * accumulated depreciation account is not the one its category says, which is the
 * one configuration mistake this module cannot detect after the fact.
 *
 * acquisition_account_id IS fillable, and it has to be: it is the money side of this
 * particular purchase, and which bank account paid for which asset is a fact about
 * the purchase rather than a category-wide policy. acquisition_method is the
 * opposite case and is a route parameter instead - see the note below the fillable
 * list.
 */
#[Fillable([
    'fixed_asset_category_id',
    'name',
    'description',
    'serial_number',
    'supplier_reference',
    'acquisition_date',
    'original_cost',
    'salvage_value',
    'depreciation_start_date',

    /*
     * acquisition_account_id IS fillable, and acquisition_method is NOT.
     *
     * That asymmetry is the point. Which account the credit side of the
     * capitalisation entry lands in depends entirely on the method - cash/bank for a
     * cash purchase, a payable for one on supplier credit - and a payload that
     * carried both could name a liability account and declare the method CASH. The
     * resolver would catch the disagreement, but only after the client had had the
     * chance to make it.
     *
     * So the method is a property of the ROUTE, as it is for cash/bank transaction
     * types, and the service takes it as an argument the controller supplies from the
     * route rather than from the request body. The account is genuinely a per-asset
     * fact - which bank account paid for which van - so it stays in the payload, and
     * it is validated against the route's method.
     */
    'acquisition_account_id',
])]
class FixedAsset extends Model
{
    /** @use HasFactory<FixedAssetFactory> */
    use HasFactory;

    /**
     * company_id, asset_number, status, useful_life_months, depreciation_method,
     * all five category accounts, journal_id, capitalised_at, capitalised_by,
     * disposed_at, disposed_by, fully_depreciated_at, created_by and updated_by are
     * all absent on purpose.
     *
     * asset_number is allocated from the document number sequence. useful_life_months
     * and depreciation_method are copied from the category and frozen. status and
     * every date and user column are server decisions, written with forceFill()
     * inside a transaction by the service. No request rule validates any of them and
     * no controller passes one through, so a client cannot reach them.
     */
    protected function casts(): array
    {
        return [
            'status' => FixedAssetStatus::class,
            'depreciation_method' => DepreciationMethod::class,
            'acquisition_method' => FixedAssetAcquisitionMethod::class,
            'acquisition_date' => 'date',
            'depreciation_start_date' => 'date',
            'capitalised_at' => 'datetime',
            'disposed_at' => 'datetime',
            'fully_depreciated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'fixed_asset_category_id');
    }

    /**
     * The capitalisation entry.
     *
     * Null for a draft and non-null thereafter in every path this application
     * writes. There is no CHECK constraint saying so, because MySQL will not accept
     * one on a column carrying ON DELETE SET NULL - error 3823 - so FixedAssetService
     * guarantees it instead. See the note in the migration.
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return HasMany<FixedAssetDepreciation, $this>
     */
    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderBy('period_number');
    }

    /**
     * At most one, enforced by a unique constraint on fixed_asset_disposals
     * .fixed_asset_id rather than by the status check alone.
     */
    public function disposal(): HasOne
    {
        return $this->hasOne(FixedAssetDisposal::class);
    }

    /**
     * The account debited on capitalisation and credited on disposal.
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    /**
     * The contra account every depreciation entry credits, and the one the disposal
     * entry debits to remove the asset's written-down value.
     */
    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function gainOnDisposalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gain_on_disposal_account_id');
    }

    public function lossOnDisposalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'loss_on_disposal_account_id');
    }

    /**
     * The cash, bank or payable account the capitalisation entry credits.
     *
     * Which of the three it has to be is decided by acquisition_method, not by this
     * row: see FixedAssetAcquisitionMethod::requiresCashBankAccount() and
     * TransactionAccountResolver::acquisitionAccount().
     */
    public function acquisitionAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'acquisition_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function capitaliser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capitalised_by');
    }

    public function disposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function originalCostAmount(): Money
    {
        return Money::of($this->original_cost);
    }

    public function salvageValueAmount(): Money
    {
        return Money::of($this->salvage_value);
    }

    /**
     * What there is to write down: the cost less what the asset will be worth at the
     * end of its life.
     *
     * Zero when salvage equals cost, which is a legitimate configuration - an asset
     * whose entire cost is recovered - and is the reason the depreciation service has
     * to handle a zero base rather than assuming there is always something to charge.
     */
    public function depreciableBaseAmount(): Money
    {
        return $this->originalCostAmount()->minus($this->salvageValueAmount());
    }

    /**
     * The standard monthly charge, before any rounding correction.
     *
     * This is a figure for display and for planning, NOT the amount the last period
     * is posted at. The real schedule is produced by
     * FixedAssetDepreciationCalculator, which charges this figure for every period
     * except the last, where it charges whatever remains in order for the periods to
     * sum exactly to the depreciable base. A caller that posts this value directly
     * would leave a residual amount permanently undepreciated.
     */
    public function monthlyChargeAmount(): Money
    {
        return $this->depreciableBaseAmount()->dividedBy(Money::ofInt($this->useful_life_months));
    }

    /**
     * How many depreciation periods this asset has.
     *
     * Straight-line over a whole number of months, so this is exactly the useful
     * life. The distinction from monthlyChargeAmount() is that this one is a count,
     * not an amount, and the two are not required to agree on anything - an asset may
     * finish its life in fewer periods than it has if it is disposed early.
     */
    public function periodCount(): int
    {
        return (int) $this->useful_life_months;
    }

    /**
     * Total depreciation posted to date.
     *
     * DERIVED, every call. The sum runs over posted rows only, because a row is only
     * ever created in the same transaction that posts its journal - so there is no
     * unposted depreciation to exclude and no cache that could fall out of step with
     * the ledger.
     *
     * Raw DB rather than the Eloquent relation so the result is MySQL's SUM over
     * DECIMAL, which is exact. A hydrated collection would be correct too and slower,
     * and the reason is that this runs once per asset per schedule read.
     */
    public function accumulatedDepreciationAmount(): Money
    {
        /*
         * Three ways to answer, in order of how the caller arranged the data.
         *
         * A list endpoint aggregates in SQL with withSum(), which leaves
         * depreciations_sum_amount on the row and lets a page of assets be valued
         * without a query each. A single-asset endpoint eager-loads the rows, and
         * those are summed in memory. Neither is present on the posting path, which
         * calls this on a freshly locked asset with nothing loaded and gets the
         * aggregate query below.
         *
         * All three agree because MySQL sums DECIMAL exactly and the in-memory path
         * reduces with Money rather than with Collection::sum(), which would add the
         * strings as floats and drift on a long register.
         */
        if (array_key_exists('depreciations_sum_amount', $this->attributes)) {
            return Money::of((string) ($this->attributes['depreciations_sum_amount'] ?? '0'));
        }

        if ($this->relationLoaded('depreciations')) {
            return $this->depreciations->reduce(
                fn (Money $carry, FixedAssetDepreciation $row): Money => $carry->plus(Money::of($row->amount)),
                Money::zero()
            );
        }

        $total = $this->depreciations()->sum('amount');

        return Money::of((string) $total);
    }

    /**
     * What the asset is worth on the balance sheet right now.
     *
     * Derived, and guaranteed not to go negative: an asset cannot be worth less than
     * its salvage value, and the rounding correction in the schedule exists partly so
     * that this subtraction lands on the salvage value exactly rather than a fraction
     * either side of it. The clamp states the invariant rather than relying on the
     * calculator to have honoured it.
     */
    public function carryingAmount(): Money
    {
        $carrying = $this->originalCostAmount()->minus($this->accumulatedDepreciationAmount());

        return $carrying->lessThan($this->salvageValueAmount())
            ? $this->salvageValueAmount()
            : $carrying;
    }

    /**
     * How much of the asset's life is left, as a whole number of unposted periods.
     *
     * Zero once fully depreciated, and never negative. Used by the schedule endpoint
     * to report an asset as finished rather than to decide anything - whether a
     * depreciation may be posted is asked of the status, not of this number.
     */
    public function remainingPeriodCount(): int
    {
        /*
         * A list endpoint aggregates in SQL with withMax(), which leaves
         * depreciations_max_period_number on the row; everywhere else the aggregate
         * query below runs. Same two-path arrangement as accumulatedDepreciationAmount,
         * and for the same reason: no per-asset query on a register page.
         */
        $posted = array_key_exists('depreciations_max_period_number', $this->attributes)
            ? (int) ($this->attributes['depreciations_max_period_number'] ?? 0)
            : (int) $this->depreciations()->max('period_number');

        return max(0, $this->periodCount() - $posted);
    }

    /**
     * Has every period of this asset's life been charged?
     *
     * Asked by the depreciation service immediately after posting the final period,
     * and the answer decides whether the asset becomes FULLY_DEPRECIATED or stays
     * ACTIVE. It compares against the period COUNT rather than against the
     * accumulated amount, so an asset whose base rounds to zero in the early periods
     * still finishes on the period it is due to rather than never at all.
     */
    public function hasCompletedDepreciation(): bool
    {
        return $this->remainingPeriodCount() === 0;
    }

    /**
     * Is this asset still depreciating?
     *
     * Delegates to the enum so that the rule has one answer in the codebase. A
     * disposed asset is not depreciable even though its periods all ran, which is the
     * case a period count alone would get wrong.
     */
    public function isDepreciable(): bool
    {
        return $this->status->isDepreciable();
    }

    public function isDisposable(): bool
    {
        return $this->status->isDisposable();
    }

    /**
     * @param  Builder<FixedAsset>  $query
     * @return Builder<FixedAsset>
     */
    public function scopeOnRegister(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FixedAssetStatus::Active->value,
            FixedAssetStatus::FullyDepreciated->value,
        ]);
    }

    /**
     * @param  Builder<FixedAsset>  $query
     * @return Builder<FixedAsset>
     */
    public function scopeDepreciable(Builder $query): Builder
    {
        return $query->where('status', FixedAssetStatus::Active->value);
    }

    /**
     * @param  Builder<FixedAsset>  $query
     * @return Builder<FixedAsset>
     */
    public function scopeDisposed(Builder $query): Builder
    {
        return $query->where('status', FixedAssetStatus::Disposed->value);
    }

    /*
     * There is deliberately NO scope for "assets due for depreciation as of date X".
     *
     * It is the query the monthly run most obviously wants, and it is not expressible
     * as one. Whether an asset is due depends on the start of its next UNPOSTED
     * period, which is a function of how many periods have already been charged -
     * state that lives in another table, one row per period. So the question is not
     * "which assets start before this date" but "which assets have a next period
     * that has already begun", and the second needs the first to be answered per
     * asset.
     *
     * A scope that compared depreciation_start_date against the as-of date would
     * return assets that are not yet due - one capitalised yesterday has a first
     * period ending a month from now - and would omit nothing, so it would look
     * right while charging depreciation early for every asset in the register.
     *
     * The monthly run therefore selects scopeDepreciable() and asks
     * FixedAssetDepreciationCalculator for each asset's next period date, which is
     * exact and is the same code path a single manual posting takes.
     */
}
