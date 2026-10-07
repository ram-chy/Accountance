<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * An exchange rate: units of the target currency per one unit of the source.
 *
 * WHY THIS IS NOT Money
 *
 * Because a rate is not an amount. Money is fixed at four decimal places because
 * that is the ledger's precision, and a rate has to survive ten: IDR to USD is
 * about 0.000062, and stored at four places that becomes 0.0001 - a 61% error in
 * the conversion factor, introduced silently, by reusing the right-looking class.
 *
 * Reusing Money with a different scale was considered and rejected: Money::scale()
 * reads a single global config value, and a second scale for the same class would
 * make "which scale is this Money at?" a question with two answers depending on
 * which column it came from. A separate type makes the compiler-in-a-comment
 * boundary explicit - you cannot pass a rate where an amount is expected without
 * noticing.
 *
 * WHY THE RATE IS STORED AND NEVER DIVIDED
 *
 * The convention throughout Phase 14 is "base per one unit of transaction currency",
 * so conversion is always a multiplication. Dividing - which is what a stored
 * "transaction per base" rate would require - is deliberately not offered: it
 * loses precision at every step, and its failure mode is a rate that is off by a
 * factor of the rate itself rather than by a rounding digit. A 61% error reads as
 * a real number, which is why it is worth a class.
 *
 * The reciprocal is still obtainable, for display and for cross-checking a rate a
 * user typed the other way round, via reciprocal(). It is a separate, explicit call
 * for exactly that reason.
 */
final readonly class Rate implements Stringable
{
    /**
     * The rate, always carrying exactly `scale` decimal places.
     */
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Build a rate from any stringable/int representation.
     *
     * Strict, unlike Money::ofTolerant(): a rate is entered deliberately by someone
     * who has looked it up, so excess precision is a mistake worth reporting rather
     * than silently rounding. It is still accepted when the extra digits are
     * trailing zeroes, because "83.50000000000" is the same rate written longer and
     * refusing it would be pedantry rather than rigour.
     *
     * @throws InvalidArgumentException when the value is malformed or is not positive
     */
    public static function of(int|float|string $value): self
    {
        if (is_float($value)) {
            $value = number_format($value, 10, '.', '');
        }

        $value = trim((string) $value);
        $value = str_replace([',', '_', ' '], '', $value);
        $value = ltrim($value, '+');

        if (str_contains($value, 'e') || str_contains($value, 'E')) {
            $value = self::expandExponential($value);
        }

        if (preg_match('/^-?\d*(\.\d*)?$/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            throw new InvalidArgumentException("Malformed exchange rate [{$value}].");
        }

        /*
         * Rejected here rather than at the request layer because a non-positive
         * rate is not a matter of formatting: zero would convert every amount to
         * zero and a negative rate has no market meaning. Both are also refused by
         * the database, because a value that must be right for every posted
         * document should not depend on a service having been on the path.
         */
        if (bccomp($value, '0', 20) <= 0) {
            throw new InvalidArgumentException('An exchange rate must be greater than zero.');
        }

        $scale = self::scale();

        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '-');

        [$integer, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

        if (strlen($fraction) > $scale && substr($fraction, $scale) !== str_repeat('0', strlen($fraction) - $scale)) {
            throw new InvalidArgumentException(
                "Exchange rate [{$value}] has more precision than the configured scale of {$scale} decimal places."
            );
        }

        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');

        $canonical = ($negative ? '-' : '').($integer === '' ? '0' : $integer).'.'.$fraction;

        return new self(bcadd($canonical, '0', $scale));
    }

    /**
     * The identity rate: one unit of a currency is one unit of itself.
     *
     * Used for a document in the company's own base currency. This is the
     * representation of "no conversion happened" - but note that callers persist
     * NULL for that case, not 1, because a rate of 1 on a line implies someone
     * quoted a rate when in fact there was nothing to quote. one() is for the
     * arithmetic, where the multiplier genuinely is 1.
     */
    public static function one(): self
    {
        return self::of('1');
    }

    public function applyTo(Money $amount): Money
    {
        return Money::product($amount->toDatabase(), $this->value);
    }

    /**
     * The inverse rate, for a pair entered the other way round.
     *
     * Explicitly separate from applyTo() rather than offered as a flag on it: the
     * inverse is what you want to *show* someone who typed the pair backwards, not
     * something the conversion path should quietly reach for. If a conversion needs
     * a reciprocal, the rate was stored the wrong way round and that is a data
     * problem worth seeing.
     */
    public function reciprocal(): self
    {
        return self::of(bcdiv('1', $this->value, self::scale() + 10));
    }

    public function equals(self $other): bool
    {
        return bccomp($this->value, $other->value, self::scale()) === 0;
    }

    public function isOne(): bool
    {
        return $this->equals(self::one());
    }

    public function toDatabase(): string
    {
        return $this->value;
    }

    /**
     * The rate's own decimal scale, from config rather than from the database.
     *
     * config/accounting.php states the scale the DECIMAL(20,10) columns were built
     * at, so the value object and the schema cannot drift. A rate column widened
     * without widening this would round silently; a migration changing the column
     * has to change this in the same commit, which is the point of reading one value
     * in one place.
     */
    public static function scale(): int
    {
        return (int) config('accounting.exchange_rate.scale', 10);
    }

    private static function expandExponential(string $value): string
    {
        $parts = preg_split('/[eE]/', $value);

        if ($parts === false || count($parts) !== 2) {
            throw new InvalidArgumentException("Malformed exchange rate [{$value}].");
        }

        [$mantissa, $exponent] = $parts;

        $exponent = (int) $exponent;
        $negative = str_starts_with($mantissa, '-');
        $mantissa = ltrim($mantissa, '-');

        [$integer, $fraction] = array_pad(explode('.', $mantissa, 2), 2, '');

        $digits = $integer.$fraction;
        $pointPosition = strlen($integer) + $exponent;

        if ($pointPosition <= 0) {
            $expanded = '0.'.str_repeat('0', -$pointPosition).$digits;
        } elseif ($pointPosition >= strlen($digits)) {
            $expanded = $digits.str_repeat('0', $pointPosition - strlen($digits));
        } else {
            $expanded = substr($digits, 0, $pointPosition).'.'.substr($digits, $pointPosition);
        }

        return ($negative ? '-' : '').$expanded;
    }
}
