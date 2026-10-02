<?php

namespace App\Services\Accounting\Tax;

use App\Models\Tax;
use App\Models\TaxRate;
use App\Support\Money;

/**
 * A tax paired with the rate that applies on the date being calculated.
 *
 * Exists so the calculation carries no query and no branching. `rate` is nullable
 * only so a resolution failure can be reported for all taxes at once rather than
 * on the first one; a ResolvedTaxRate with a null rate never reaches the
 * arithmetic, because `resolveRates()` throws before returning.
 */
final readonly class ResolvedTaxRate
{
    public function __construct(
        public Tax $tax,
        public ?TaxRate $rate,
        public Money $rateAmount,
    ) {}
}
