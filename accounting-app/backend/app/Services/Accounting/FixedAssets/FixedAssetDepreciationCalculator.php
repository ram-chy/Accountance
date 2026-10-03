<?php

namespace App\Services\Accounting\FixedAssets;

use App\Models\FixedAsset;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Straight-line depreciation arithmetic for a fixed asset.
 *
 * PURE. No database, no queries, no side effects, no authenticated context. It is
 * handed an asset and an already-loaded set of posted periods and answers questions
 * about the schedule. That is not tidiness for its own sake - it is what lets the
 * same code serve the monthly posting run, the schedule endpoint a user reads and
 * the disposal calculation, and be tested directly with no fixture company at all.
 *
 * WHY A SERVICE AND NOT A MODEL METHOD
 *
 * FixedAsset::monthlyChargeAmount() exists too, and deliberately returns only the
 * unadjusted figure, because that value is useful for display but WRONG to post for
 * the final period. Everything about which period comes next, what date it ends on
 * and what it should charge is here, because it depends on state outside the asset -
 * how many periods have been charged - and a method that appeared to answer it while
 * quietly ignoring it would be the more dangerous of the two.
 *
 * THE ROUNDING RULE, WHICH IS THE WHOLE REASON THIS EXISTS
 *
 * The depreciable base does not generally divide by the number of periods. An asset
 * costing 10,000 with 1,000 salvage over 36 months has a monthly charge of 250
 * exactly, and needs no help. One costing 9,999.99 with 0 salvage over 12 months
 * has a monthly charge of 833.3325, which does not.
 *
 * The naive approaches both fail in a way that shows up years later:
 *
 *   Round each period independently. The periods then sum to something fractionally
 *   different from the base, and the asset never reaches salvage value. The
 *   difference is small, but it is permanent and it means the last period charges a
 *   different amount from all the others with nothing to explain why.
 *
 *   Let the last period be whatever remains. Correct, but only if "the last period"
 *   is identified correctly - and it has to be identified by the PERIOD COUNT, not
 *   by "the accumulated total is close enough", or an asset whose base rounds to zero
 *   in the early periods never completes at all.
 *
 * So: every period charges the standard monthly figure, and the final period charges
 * the standard figure plus whatever is left over - or minus it, if rounding has
 * overshot. The periods sum to the base exactly, and the adjustment appears once, in
 * one period, which is where a reader of the schedule will find it.
 *
 * Money::dividedBy() does the half-up rounding to the stored scale, so the standard
 * figure is computed once and reused rather than re-derived per period, which also
 * guarantees all the non-final periods are identical to each other.
 */
final class FixedAssetDepreciationCalculator
{
    /**
     * The standard monthly charge, rounded half-up to the stored scale.
     *
     * The same arithmetic as FixedAsset::monthlyChargeAmount(), reached through the
     * model so there is one implementation. This exists so a caller with an asset in
     * hand can ask without reaching for a relation.
     */
    public function monthlyCharge(FixedAsset $asset): Money
    {
        return $asset->monthlyChargeAmount();
    }

    /**
     * How many periods the asset's life runs for.
     *
     * The useful life in months. Straight-line over whole months, so the count is
     * exact - there is no partial final period to reason about, which is the main
     * simplification this phase's single depreciation method buys.
     */
    public function periodCount(FixedAsset $asset): int
    {
        return $asset->periodCount();
    }

    /**
     * The number of the next period to be charged.
     *
     * One past the highest period already posted, so the first period of a fresh
     * asset is 1. Derived from the POSTED periods the caller passes in rather than
     * from a count of rows, so that a period numbered 7 with periods 1-6 also present
     * cannot make the next one come out as 8 when 7 is genuinely the next one due.
     */
    public function nextPeriodNumber(FixedAsset $asset, int $highestPostedPeriod): int
    {
        return $highestPostedPeriod + 1;
    }

    /**
     * The first day of a given period.
     *
     * The asset's depreciation_start_date advanced by whole months, using
     * addMonthsNoOverflow so that a month with fewer days clamps rather than
     * overflowing into the one after it.
     *
     * The consequence of clamping is that an asset starting on 31 January has its
     * second period beginning on 28 February, and its third on 28 March - the day
     * settles at 28 and stays there for the rest of its life. That is the ordinary
     * behaviour of anniversary-based depreciation and it is preferred here to the
     * alternative of letting the date drift forward a day at a time (31 Jan, 1 Mar,
     * 1 Apr, 2 May), which would make the periods successively longer and charge a
     * few extra days in each long month.
     *
     * Either way the periods are consecutive: period N+1 begins the day after period
     * N ends, because periodEnd() is defined in terms of the next period's start
     * rather than by its own arithmetic.
     */
    public function periodStart(FixedAsset $asset, int $periodNumber): Carbon
    {
        return $asset->depreciation_start_date->copy()->addMonthsNoOverflow($periodNumber - 1);
    }

    /**
     * The last day of a given period, inclusive.
     *
     * The day before the next period begins. Expressed that way rather than as
     * "start plus one month minus one day" so that the two boundaries cannot disagree
     * for any month length: period 1 ends on the day before period 2 starts, by
     * definition, and there is no arithmetic that could make them inconsistent.
     */
    public function periodEnd(FixedAsset $asset, int $periodNumber): Carbon
    {
        return $this->periodStart($asset, $periodNumber + 1)->subDay();
    }

    /**
     * Has the given period's charge become postable?
     *
     * True once the period has ended. This is the only question about timing in the
     * whole schedule, and it is asked per asset rather than answered once for the
     * register, because each asset's periods fall on its own anniversary.
     */
    public function periodHasElapsed(FixedAsset $asset, int $periodNumber, Carbon $asOf): bool
    {
        return $this->periodEnd($asset, $periodNumber)->lessThanOrEqualTo($asOf->copy()->endOfDay());
    }

    /**
     * How much is still left to depreciate.
     *
     * The depreciable base less what has already been charged, floored at zero.
     * The floor matters for an asset configured with salvage equal to its cost - a
     * base of zero, where nothing is ever chargeable and every method here must
     * return a zero amount rather than a negative one that would post as a credit.
     */
    public function remainingDepreciableAmount(FixedAsset $asset, Money $alreadyCharged): Money
    {
        $remaining = $asset->depreciableBaseAmount()->minus($alreadyCharged);

        return $remaining->isNegative() ? Money::zero() : $remaining;
    }

    /**
     * The charge for one period, taking the final-period correction into account.
     *
     * $alreadyCharged is the total posted to the asset before this period. It is a
     * parameter rather than something derived here because the calculator does not
     * read the database: the caller has the periods in hand and passes the sum.
     *
     * A zero amount is returned rather than a refusal when there is nothing left to
     * charge - an asset whose base is zero, or one whose periods already exhaust it.
     * The CHECK constraint on fixed_asset_depreciations.amount requires a positive
     * amount, so the service must not write a row in that case, and this method is
     * what tells it not to. Returning zero rather than throwing is deliberate: "this
     * asset has finished" is a normal outcome to be told about, not an error to
     * raise, and the service distinguishes it by asking isZero().
     */
    public function chargeForPeriod(FixedAsset $asset, int $periodNumber, Money $alreadyCharged): Money
    {
        $remaining = $this->remainingDepreciableAmount($asset, $alreadyCharged);

        if ($remaining->isZero()) {
            return Money::zero();
        }

        $isFinal = $periodNumber >= $this->periodCount($asset);

        /*
         * The final period absorbs the difference between what the standard charge
         * would come to across every period and what is actually left, so the sum of
         * all periods equals the depreciable base exactly.
         *
         * The remainder can be negative - rounding twelve monthly charges of 833.3325
         * up to 833.3325 each may overshoot the base by a fraction - so this is a
         * subtraction that can come out either way, and no max() is applied. Clamping
         * to zero would leave the asset short of fully depreciated by exactly the
         * fraction that overshot, forever.
         *
         * It is also never negative in the end, because remainingDepreciableAmount()
         * already floored at zero and the standard charge cannot exceed what remains
         * when there is a single period left.
         */
        if ($isFinal) {
            return $remaining;
        }

        return $this->monthlyCharge($asset);
    }

    /**
     * Build the period object for one period.
     *
     * The single place the three parts of a period are assembled together, so the
     * number, the dates and the amount in a DepreciationPeriod always agree with
     * each other and with the arithmetic above.
     */
    public function period(
        FixedAsset $asset,
        int $periodNumber,
        Money $alreadyCharged
    ): DepreciationPeriod {
        return new DepreciationPeriod(
            number: $periodNumber,
            startDate: $this->periodStart($asset, $periodNumber),
            endDate: $this->periodEnd($asset, $periodNumber),
            amount: $this->chargeForPeriod($asset, $periodNumber, $alreadyCharged),
            isFinal: $periodNumber >= $this->periodCount($asset),
        );
    }

    /**
     * The asset's whole remaining schedule, in order.
     *
     * For the schedule endpoint and the report - a display concern, since posting is
     * one period at a time. Stops at the period where the remaining base is
     * exhausted, so an asset with a zero base yields an empty schedule rather than
     * one full of zero-amount periods the CHECK constraint would refuse.
     *
     * @param  array<int, array{number: int, amount: Money}>  $postedPeriods  as returned by postedPeriods()
     * @return array<int, DepreciationPeriod>
     */
    public function schedule(FixedAsset $asset, array $postedPeriods): array
    {
        $charged = $this->sumOf($postedPeriods);
        $next = $this->nextPeriodNumber($asset, $this->highestPeriodNumber($postedPeriods));

        $periods = [];

        for ($number = $next; $number <= $this->periodCount($asset); $number++) {
            $period = $this->period($asset, $number, $charged);

            if ($period->amount->isZero()) {
                break;
            }

            $periods[] = $period;

            $charged = $charged->plus($period->amount);
        }

        return $periods;
    }

    /**
     * Reduce posted depreciation rows to what the calculator needs.
     *
     * Deliberately a projection rather than a Collection of models: the calculator
     * must not be able to reach a journal or a company through what it is handed, so
     * that the arithmetic provably has no access to the ledger.
     *
     * @param  iterable<int, FixedAssetDepreciationSummary>  $rows
     * @return array<int, array{number: int, amount: Money}>
     */
    public function postedPeriods(iterable $rows): array
    {
        $periods = [];

        foreach ($rows as $row) {
            $periods[] = [
                'number' => $row->number,
                'amount' => $row->amount,
            ];
        }

        return $periods;
    }

    /**
     * The first period number missing from the posted set, or null if there is none.
     *
     * The order rule this phase relies on - earliest unposted period only - is only
     * meaningful if the posted periods run 1, 2, 3 ... with nothing absent. Posting
     * the next period after a gap would still satisfy the two unique constraints
     * (nothing is duplicated) while silently leaving a hole: the periods would no
     * longer sum to the depreciable base, and the final-period correction, defined as
     * "whatever remains", would land in the wrong period and for the wrong amount.
     *
     * So the gap is detected rather than posted over. A set with numbers [1, 2, 4]
     * reports 3; a contiguous set reports null. The numbers do not need to arrive
     * sorted.
     *
     * @param  array<int, array{number: int, amount: Money}>  $postedPeriods
     */
    public function firstMissingPeriod(array $postedPeriods): ?int
    {
        $numbers = array_map(fn (array $period) => $period['number'], $postedPeriods);
        sort($numbers);

        foreach ($numbers as $index => $number) {
            if ($number !== $index + 1) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * The highest period number already posted, or 0 when none have been.
     *
     * max() rather than count() because the two differ for any asset with a gap, and
     * the gap case is exactly what the second unique constraint on
     * fixed_asset_depreciations exists to make detectable.
     *
     * @param  array<int, array{number: int, amount: Money}>  $postedPeriods
     */
    public function highestPeriodNumber(array $postedPeriods): int
    {
        $highest = 0;

        foreach ($postedPeriods as $period) {
            $highest = max($highest, $period['number']);
        }

        return $highest;
    }

    /**
     * @param  array<int, array{number: int, amount: Money}>  $postedPeriods
     */
    public function sumOf(array $postedPeriods): Money
    {
        $total = Money::zero();

        foreach ($postedPeriods as $period) {
            $total = $total->plus($period['amount']);
        }

        return $total;
    }
}
