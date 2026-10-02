<?php

namespace App\Enums;

/**
 * Whether the amount a caller submits is the net amount or the amount including
 * tax.
 *
 * Two directions, and the arithmetic for each is genuinely different rather than
 * a rearrangement of the other:
 *
 *   Exclusive  net 100, rate 10%  ->  tax = 100 x 10 / 100          = 10
 *               gross = net + tax                                    = 110
 *
 *   Inclusive  gross 110, rate 10% ->  tax = 110 x 10 / (100 + 10)   = 10
 *               net = gross - tax                                    = 100
 *
 * The inclusive direction divides by (100 + rate) rather than by 100, and that
 * is the whole reason this is an enum on the calculation rather than a boolean
 * on the request: a tax engine that offers "reverse the signs" for this gets
 * the divisor wrong, and the error is small enough to look plausible on an
 * invoice.
 *
 * A tax's basis is a property of the tax, not of a document, so it is configured
 * once on `Tax::calculation_basis`. It is not inferred from the tax type: a tax
 * of type BOTH is still charged, or quoted, on one basis only.
 *
 * The engine reads the basis from the caller rather than hard-coding either
 * branch, so a caller holding a net figure may calculate an exclusive result for
 * an inclusive-configured tax. That is why the enum sits on the calculation
 * input as well as the tax: the configured default is a default, not a
 * constraint on what may be asked for.
 */
enum TaxCalculationBasis: string
{
    case Exclusive = 'EXCLUSIVE';
    case Inclusive = 'INCLUSIVE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
