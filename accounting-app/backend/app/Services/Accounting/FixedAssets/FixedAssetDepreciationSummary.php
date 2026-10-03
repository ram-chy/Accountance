<?php

namespace App\Services\Accounting\FixedAssets;

use App\Support\Money;

/**
 * One posted depreciation period, reduced to what the arithmetic needs.
 *
 * The calculator is handed these rather than FixedAssetDepreciation models so that
 * the straight-line arithmetic provably cannot reach a journal, an account or a
 * company. That is the point of keeping the calculator pure, and it is cheap to
 * enforce: the two fields below are the only facts the schedule depends on, and
 * making them the ONLY things it can be handed means no future method can
 * accidentally start querying.
 *
 * Built by the service from the loaded rows. It is a projection, not a cache -
 * nothing holds one, and it is discarded as soon as the schedule is calculated.
 */
final readonly class FixedAssetDepreciationSummary
{
    public function __construct(
        /**
         * The period number as it was posted. Read rather than inferred, because the
         * second unique constraint on fixed_asset_depreciations exists to catch rows
         * whose number and date disagree, and the calculator has to work from what
         * the table actually says rather than from an assumption about what it should
         * have said.
         */
        public int $number,

        /**
         * The amount charged, already in Money form. MySQL sums DECIMAL exactly, so
         * the service wraps the raw value with Money::of() before it gets here and the
         * arithmetic never touches a float.
         */
        public Money $amount,
    ) {}
}
