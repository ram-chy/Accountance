<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * An exact monetary amount.
 *
 * Money is never a PHP float. Binary floating point cannot represent 0.10, and
 * a ledger that sums 0.10 + 0.20 as 0.30000000000000004 has no defensible
 * answer for "is this journal balanced?". Every amount that enters or leaves
 * the accounting engine passes through this class, which stores a decimal string
 * at a fixed scale and does arithmetic with bcmath.
 *
 * The scale is fixed globally in config/accounting.php so the value object and
 * the DECIMAL columns cannot drift apart. Instances are immutable; every
 * operation returns a new instance.
 */
final readonly class Money implements Stringable
{
    /**
     * The stored value, always carrying exactly `scale` decimal places and
     * always canonicalised so that equal amounts have equal strings.
     */
    private string $amount;

    private function __construct(string $amount)
    {
        $this->amount = $amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    /**
     * Build from any stringable/int representation.
     *
     * Floats are accepted at the boundary because that is where they arrive
     * (JSON decoding), but they are converted through a fixed 4-decimal string
     * representation rather than used arithmetically. Precision beyond the
     * configured scale is rejected here rather than silently rounded; the
     * request layer decides whether to tolerate it.
     */
    public static function of(int|float|string $value): self
    {
        if (is_float($value)) {
            $value = number_format($value, self::scale(), '.', '');
        }

        $value = trim((string) $value);

        if ($value === '') {
            $value = '0';
        }

        // Accept a leading + or thousands separators from human input, but the
        // canonical stored form is always plain decimal.
        $value = str_replace([',', '_', ' '], '', $value);
        $value = ltrim($value, '+');

        if (str_contains($value, 'e') || str_contains($value, 'E')) {
            $value = self::expandExponential($value);
        }

        if (! self::isWellFormed($value)) {
            throw new InvalidArgumentException("Malformed monetary amount [{$value}].");
        }

        $scale = self::scale();

        [$integer, $fraction] = self::split($value);

        $fraction = self::padOrReject($fraction, $scale, $value);

        // Build the canonical "<integer>.<fraction>" string first. The fraction
        // is the trailing digits, not a number to add, so it must never be fed
        // to bcmath as if it were one.
        $whole = ($integer === '' ? '0' : $integer).'.'.$fraction;

        return new self(self::canonicalise(bcadd($whole, '0', $scale)));
    }

    /**
     * Build from an amount that carries more precision than the system stores.
     *
     * Used at the API boundary, where a client may send 0.00005 for a ledger
     * that keeps 4 decimals. The value is rounded half-up - the convention
     * accountants use for money - and then validated like any other amount, so
     * a rounded-away-to-nothing value still fails the "must be positive" rule
     * later instead of becoming a zero-value line.
     */
    public static function ofTolerant(int|float|string $value): self
    {
        return self::of(self::roundToScale($value));
    }

    private static function roundToScale(int|float|string $value): string
    {
        if (is_float($value)) {
            $value = number_format($value, 10, '.', '');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '0';
        }

        $value = str_replace([',', '_', ' '], '', $value);
        $value = ltrim($value, '+');

        // Anything that is not a plain decimal is handed to the strict parser
        // so it fails with the standard "malformed" message.
        if (preg_match('/^-?\d*(\.\d*)?$/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            return $value;
        }

        return self::roundHalfUp($value, self::scale());
    }

    /**
     * Round a decimal string to the given scale, half away from zero.
     *
     * bcmath has no rounding mode - bcadd() truncates toward zero, which would
     * turn 1.00005 into 1.0000 and silently lose a half-cent. Implementing the
     * rule here keeps the documented "half-up" behaviour honest instead of
     * describing truncation as rounding.
     *
     * Half away from zero rather than half toward positive infinity, so that
     * -1.00005 and 1.00005 behave symmetrically. Rounding only ever shrinks the
     * magnitude here, so no result can exceed DECIMAL(20,4)'s capacity.
     */
    private static function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '-');

        [$integer, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

        if (strlen($fraction) <= $scale) {
            return bcadd(
                ($negative ? '-' : '').($integer === '' ? '0' : $integer).'.'.str_pad($fraction, $scale, '0'),
                '0',
                $scale
            );
        }

        /*
         * Rounding to the scale, then adding one unit when the first dropped
         * digit is 5 or more. Expressing it as an addition rather than an
         * increment to the digit string is what makes the carry correct: 9.99995
         * becomes 10.0000 because the addition happens in bcmath, whereas
         * incrementing the truncated digits as a string would need the same
         * carry logic re-implemented by hand.
         *
         * Only the first dropped digit decides. Anything at or above half rounds
         * away from zero - 1.00005 and 1.000051 are both >= 1.00005, so both
         * round up. There is no round-to-even case here.
         */
        $truncated = ($integer === '' ? '0' : $integer).'.'.substr($fraction, 0, $scale);

        if ((int) $fraction[$scale] >= 5) {
            // One unit in the last place: 0.0001 at scale 4, 0.1 at scale 1.
            $unit = $scale > 0
                ? '0.'.str_repeat('0', $scale - 1).'1'
                : '1';

            $truncated = bcadd($truncated, $unit, $scale);
        }

        return bcadd(($negative ? '-' : '').$truncated, '0', $scale);
    }

    public static function zero(): self
    {
        return new self(self::zeroString());
    }

    public static function ofInt(int $value): self
    {
        return self::of((string) $value);
    }

    public function plus(self $other): self
    {
        return new self(self::canonicalise(bcadd($this->amount, $other->amount, self::scale())));
    }

    public function minus(self $other): self
    {
        return new self(self::canonicalise(bcsub($this->amount, $other->amount, self::scale())));
    }

    /**
     * Multiply by a whole number, used for quantity x unit-price style lines.
     *
     * Kept deliberately narrow: arbitrary decimal multiplication needs a
     * documented rounding policy and produces scale creep, and no accounting
     * rule in this phase requires it.
     */
    public function timesInt(int $multiplier): self
    {
        return new self(self::canonicalise(
            bcmul($this->amount, (string) $multiplier, self::scale())
        ));
    }

    /**
     * Multiply by another decimal amount, rounding half-up to the stored scale.
     *
     * Phase 5 needs this: an invoice line is quantity x unit price, and quantity
     * is DECIMAL(12,4) because a sale may be for 2.5 hours of work or 0.5 tonnes
     * of material. timesInt() cannot express that, and doing it in float would
     * reintroduce exactly the error this class exists to prevent - 2.5 x 19.99 is
     * 49.975, which as a double is 49.974999999999997.
     *
     * The product is computed at double scale before rounding, not at the stored
     * scale. Truncating first would turn 2.5 x 19.99 into 49.9700 by discarding
     * the 5 in the hundredth place before the half-up decision could see it, so
     * the result would depend on intermediate truncation rather than on the
     * stated rounding mode. Two operands at `scale` produce a product whose
     * exact value needs at most 2 * scale places, so scale x 2 is sufficient and
     * is the widest intermediate this class ever uses.
     */
    public function times(self $multiplier): self
    {
        $workingScale = self::scale() * 2;

        $product = bcmul($this->amount, $multiplier->amount, $workingScale);

        return new self(self::canonicalise(
            self::roundHalfUp($product, self::scale())
        ));
    }

    /**
     * Apply a percentage rate to this amount, rounding half-up to the stored scale.
     *
     * The transaction tax mechanism needs this, and only this much of a tax
     * engine: a rate expressed as a percentage (20.0000 meaning 20%) times a net
     * amount, giving the tax on that line. The brief explicitly rules out
     * jurisdiction rules, exemptions and filing, so a rate and an amount are the
     * whole vocabulary here - which is also why the rate is not modelled as a
     * fraction that callers could pass as 0.2 or 20 interchangeably.
     *
     * Rounding is half-up, once, at the end. Applying the rate to a rounded line
     * total and then rounding again would let a half-cent appear or vanish
     * depending on which order the two operations ran in, and two invoices
     * identical except for line ordering would then differ.
     */
    public function percentageOf(self $rate): self
    {
        /*
         * Dividing by 100 inside bcmul rather than multiplying by a precomputed
         * 0.01 keeps the working scale correct: 20.0000% of 199.90 is
         * 199.9000 * 20.0000 / 100, whose exact value is 39.9800 and fits in
         * scale x 2 decimal places. Rounding that once gives 39.9800.
         */
        $workingScale = self::scale() * 2;

        $value = bcdiv(
            bcmul($this->amount, $rate->amount, $workingScale),
            '100',
            $workingScale
        );

        return new self(self::canonicalise(
            self::roundHalfUp($value, self::scale())
        ));
    }

    /**
     * The tax already contained within this gross amount, for a rate expressed
     * as a percentage.
     *
     * This is not percentageOf() with the argument order swapped, and the
     * difference is the divisor. 20% of a net 199.90 is 39.98, so a gross of
     * 239.88 contains 39.98 - which is 239.88 x 20 / 120, not 239.88 x 20 / 100.
     * Dividing by 100 would return 47.9760 and a net of 191.9040, a number that
     * looks entirely reasonable and is wrong.
     *
     * Phase 10 needs this for a tax-inclusive quote: the caller knows what the
     * customer will pay and must extract the tax already inside that figure.
     * Without this method the extraction would have to happen somewhere outside
     * Money, in float, or as `gross - gross / (1 + r/100)` - the last of which
     * is the same arithmetic with a subtraction that can lose the final digit to
     * the truncation bcdiv performs before roundHalfUp ever sees the value.
     *
     * Working scale and rounding are deliberately identical to percentageOf():
     * two operands at `scale` multiplied give scale x 2 places, dividing at that
     * scale leaves an error far below the last stored place, and the single
     * half-up rounding decides the result. Doing it any differently here would
     * mean an inclusive and an exclusive tax on the same sale could differ in
     * the last decimal for reasons that have nothing to do with the tax.
     *
     * The rate is validated by the caller, not here. A rate at or above 100
     * would make the divisor zero or negative and has no meaning, but Money does
     * not know what a "rate" is - percentageOf() accepts the same range for the
     * same reason - and TaxCalculationService rejects it with a message that
     * names the field.
     */
    public function inclusivePercentageOf(self $rate): self
    {
        $workingScale = self::scale() * 2;

        $denominator = bcadd('100', $rate->amount, $workingScale);

        $value = bcdiv(
            bcmul($this->amount, $rate->amount, $workingScale),
            $denominator,
            $workingScale
        );

        return new self(self::canonicalise(
            self::roundHalfUp($value, self::scale())
        ));
    }

    /**
     * Divide this amount by a divisor, rounding half-up to the stored scale.
     *
     * Exists because every caller that needs a quotient needs one that is
     * rounded exactly once at the stored scale, and bcdiv's own scale argument
     * is not that - it truncates. A caller asking for more working digits and
     * rounding afterwards gets what roundHalfUp() documents; a caller asking for
     * the stored scale directly gets a silently truncated figure.
     *
     * A zero divisor is rejected rather than allowed to raise bcmath's own
     * division error, which would surface as a 500 from code that had a perfectly
     * ordinary arithmetic mistake to make.
     *
     * @throws InvalidArgumentException when $divisor is zero
     */
    public function dividedBy(self $divisor): self
    {
        $workingScale = self::scale() * 2;

        if (bccomp($divisor->amount, '0', self::scale()) === 0) {
            throw new InvalidArgumentException('Cannot divide a monetary amount by zero.');
        }

        $value = bcdiv($this->amount, $divisor->amount, $workingScale);

        return new self(self::canonicalise(
            self::roundHalfUp($value, self::scale())
        ));
    }

    public function negate(): self
    {
        return new self(self::canonicalise(bcsub('0', $this->amount, self::scale())));
    }

    public function absolute(): self
    {
        return $this->isNegative() ? $this->negate() : $this;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, self::zeroString(), self::scale()) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::scale()) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::scale()) < 0;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::scale()) === 0;
    }

    public function greaterThan(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::scale()) > 0;
    }

    public function lessThan(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::scale()) < 0;
    }

    /**
     * The magnitude as a non-negative amount, for presentation in a report
     * column that has already decided which side to print on.
     */
    public function magnitude(): string
    {
        return bccomp($this->amount, '0', self::scale()) < 0
            ? self::canonicalise(bcsub('0', $this->amount, self::scale()))
            : $this->amount;
    }

    public function toDatabase(): string
    {
        return $this->amount;
    }

    public static function scale(): int
    {
        return (int) config('accounting.precision.scale', 4);
    }

    private static function zeroString(): string
    {
        return bcadd('0', '0', self::scale());
    }

    /**
     * bcmath keeps a trailing ".0000"; this trims nothing and adds nothing, only
     * normalises a redundant leading minus on zero ("-0.0000") so equality
     * comparisons on the string form stay predictable.
     */
    private static function canonicalise(string $value): string
    {
        if (bccomp($value, '0', self::scale()) === 0) {
            return self::zeroString();
        }

        // bcmath renders -0.5000 as "-.5000" when the integer part is omitted.
        if (str_starts_with($value, '-.')) {
            $value = '-0'.substr($value, 1);
        }

        return $value;
    }

    private static function isWellFormed(string $value): bool
    {
        return preg_match('/^-?\d*(\.\d*)?$/', $value) === 1
            && $value !== '-'
            && $value !== '.'
            && preg_match('/\d/', $value) === 1;
    }

    private static function split(string $value): array
    {
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        if ($negative) {
            $integer = '-'.($integer === '' ? '0' : $integer);
        }

        return [$integer, $fraction];
    }

    /**
     * Accept fewer decimals than the scale and pad them; reject more, because
     * discarding digits here would silently change an amount a user typed.
     */
    private static function padOrReject(string $fraction, int $scale, string $original): string
    {
        $length = strlen($fraction);

        if ($length <= $scale) {
            return str_pad($fraction, $scale, '0');
        }

        // Trailing zeroes beyond the scale are not a precision loss.
        if (substr($fraction, $scale) === str_repeat('0', $length - $scale)) {
            return substr($fraction, 0, $scale);
        }

        throw new InvalidArgumentException(
            "Amount [{$original}] has more precision than the configured scale of {$scale} decimal places."
        );
    }

    /**
     * Scientific notation is not something a ledger should normally see, but a
     * JSON body may carry it and silently mis-reading 1e3 as 1 would be a
     * correctness bug rather than a validation error.
     */
    private static function expandExponential(string $value): string
    {
        $parts = preg_split('/[eE]/', $value);

        if ($parts === false || count($parts) !== 2) {
            throw new InvalidArgumentException("Malformed monetary amount [{$value}].");
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
