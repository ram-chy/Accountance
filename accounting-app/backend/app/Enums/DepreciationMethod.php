<?php

namespace App\Enums;

/**
 * How an asset's cost is written down over its useful life.
 *
 * ONE case, and that is a deliberate application of the rule Phase 4 set for
 * JournalSource and Phase 11 restated for note types: an enum case exists here
 * only when something produces it. Declaring DecliningBalance or UnitsOfProduction
 * now would mean the database could hold STRAIGHT_LINE / REDUCING_BALANCE /
 * UNITS_OF_PRODUCTION while exactly one of them can be calculated, and every
 * future reader would have to work out which of the three are real. The column is
 * a string and the CHECK constraint allows only what this enum defines, so adding
 * a method later is an additive change: a new enum case, a new calculator branch,
 * and the one place that decides whether an asset may be depreciated.
 *
 * Straight-line is the method that needs no second input to compute: given a cost,
 * a salvage value and a life in months, the charge per month is determined. A
 * declining-balance method needs a rate and a convention for the final period, and
 * units-of-production needs a usage figure this application has no way of recording
 * - there is no maintenance or odometer module here, and inventing one to feed a
 * depreciation method would be the "physical asset tracking" the brief defers.
 */
enum DepreciationMethod: string
{
    case StraightLine = 'STRAIGHT_LINE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
