<?php

namespace App\Services\Accounting\FixedAssets;

use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * One period of an asset's straight-line schedule.
 *
 * Carries everything the depreciation row needs - the number, both dates and the
 * charge - so that the code which decides WHAT the charge is never also decides how
 * to record it. The row is written from this object and from the posted journal, and
 * nothing else.
 *
 * The dates are the asset's own cycle, not calendar months and not the company's
 * financial periods. An asset capitalised on 15 March has a period 1 that runs to 14
 * April; that is what owns the asset for its first month, and rounding it to
 * "March" would either charge a month of depreciation before the asset existed or
 * leave 15 days of it uncharged.
 */
final readonly class DepreciationPeriod
{
    public function __construct(
        /**
         * 1, 2, 3... over the asset's life. Unique per asset, and one of the two
         * keys the depreciation table is unique on.
         */
        public int $number,

        /**
         * The first day of the period, inclusive. Also unique per asset - the second
         * of the table's two unique keys, and the one that catches a row relabelled
         * with a wrong number.
         */
        public Carbon $startDate,

        /**
         * The last day of the period, inclusive. For an asset starting on 15 March
         * that is 14 April, so the next period begins on 15 April and the two
         * boundary dates do not overlap.
         */
        public Carbon $endDate,

        /**
         * What to charge this period.
         *
         * Equal to the asset's standard monthly charge for every period except the
         * last, which absorbs the rounding remainder so that the periods sum exactly
         * to the depreciable base.
         */
        public Money $amount,

        /**
         * Is this the final period?
         *
         * Recorded rather than recomputed by the caller from `number`, so that the
         * service which flips the asset to FULLY_DEPRECIATED is answering the same
         * question the calculator did.
         */
        public bool $isFinal,
    ) {}

    /**
     * The date this period's charge is posted under.
     *
     * The LAST day of the period, because the period is charged when it has
     * completed rather than when it begins. The monthly run asks an asset for its
     * next unposted period, sees that the period's end date has passed, and posts
     * the charge dated to that day - so the posting date is never in the future and
     * the charge never has to be backdated after the fact.
     *
     * A CONSEQUENCE WORTH NAMING, since it is visible in a report: an asset
     * capitalised on 15 March has a first period ending 14 April, so on a calendar
     * month the first charge lands in April's accounting period and March carries no
     * depreciation for it at all. That follows directly from charging a completed
     * month, and it is the alternative to the other honest option - posting period 1
     * on the day of capitalisation, which would charge for a month the company had
     * not yet owned the asset for, or dating the April charge back to March, which
     * writes into a period that has already closed by the time the run happens.
     *
     * The depreciation report groups by the period's own dates rather than by this
     * date, so a report spanning both months shows the charge in the right one
     * regardless.
     */
    public function postingDate(): Carbon
    {
        return $this->endDate;
    }
}
