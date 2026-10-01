<?php

namespace Tests\Unit;

use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money arithmetic.
 *
 * The float-equivalence test is the important one. If Money ever started using
 * float arithmetic, `0.1 + 0.2 === 0.3` would quietly become false and a
 * balanced journal could be rejected for a reason that has nothing to do with
 * the entry.
 */
class MoneyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_adds_without_float_error(): void
    {
        $sum = Money::of('0.10')->plus(Money::of('0.20'));

        $this->assertSame('0.3000', (string) $sum);
        $this->assertTrue($sum->equals(Money::of('0.3')));

        // The same sum in float is famously not 0.3.
        $this->assertNotSame(0.3, 0.1 + 0.2);
    }

    #[Test]
    public function it_adds_many_small_amounts_exactly(): void
    {
        $total = Money::zero();

        // 0.01 added a hundred times is exactly 1.00. In float this drifts.
        for ($i = 0; $i < 100; $i++) {
            $total = $total->plus(Money::of('0.01'));
        }

        $this->assertSame('1.0000', (string) $total);
    }

    #[Test]
    public function it_subtracts_to_exact_zero(): void
    {
        $this->assertTrue(Money::of('1000.00')->minus(Money::of('1000.00'))->isZero());
    }

    #[Test]
    public function it_compares_by_value_not_by_string(): void
    {
        $this->assertTrue(Money::of('1.0')->equals(Money::of('1.0000')));
        $this->assertTrue(Money::of('1.00')->lessThan(Money::of('1.01')));
        $this->assertTrue(Money::of('2.00')->greaterThan(Money::of('1.99')));
    }

    #[Test]
    public function negative_zero_is_normalised(): void
    {
        // bcmath can produce "-0.0000"; two strings that both mean zero must not
        // compare unequal, or a balanced journal would fail on a sign artefact.
        $this->assertSame('0.0000', (string) Money::of('-0.0000'));
        $this->assertTrue(Money::zero()->negate()->isZero());
    }

    #[Test]
    public function it_rejects_negative_values_at_the_boundary(): void
    {
        $money = Money::of('-100.00');

        $this->assertTrue($money->isNegative());
        $this->assertSame('100.0000', $money->magnitude());
    }

    #[Test]
    public function it_handles_the_largest_storable_amount(): void
    {
        // DECIMAL(20,4) holds 16 integer digits.
        $money = Money::of('9999999999999999.9999');

        $this->assertSame('9999999999999999.9999', (string) $money);
    }

    #[Test]
    public function it_normalises_various_input_forms(): void
    {
        $expected = '1000.0000';

        $this->assertSame($expected, (string) Money::of('1000'));
        $this->assertSame($expected, (string) Money::of('1000.00'));
        $this->assertSame($expected, (string) Money::of('1000.'));
        $this->assertSame($expected, (string) Money::of('1e3'));
        $this->assertSame($expected, (string) Money::of('1,000'));
        $this->assertSame($expected, (string) Money::of('+1000'));
        $this->assertSame($expected, (string) Money::ofInt(1000));
    }

    #[Test]
    public function it_rejects_amounts_beyond_the_configured_scale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more precision');

        Money::of('0.00005');
    }

    /**
     * Extra trailing zeros are not a precision loss, so they are accepted rather
     * than rejected. A user typing 1.00000 has not asked for more precision than
     * 1.0000.
     */
    #[Test]
    public function it_accepts_trailing_zeros_beyond_the_scale(): void
    {
        $this->assertSame('1.0000', (string) Money::of('1.000000'));
    }

    /**
     * Half away from zero, so an exact half rounds up rather than to even. This
     * matters: bcmath's bcadd() truncates, which would silently discard the
     * half-cent a user typed.
     */
    #[Test]
    public function the_tolerant_parser_rounds_half_up(): void
    {
        $this->assertSame('0.0001', (string) Money::ofTolerant('0.00005'));
        $this->assertSame('0.0002', (string) Money::ofTolerant('0.00015'));
        $this->assertSame('1.0001', (string) Money::ofTolerant('1.00005'));
        $this->assertSame('1.0000', (string) Money::ofTolerant('1.00004'));
    }

    /**
     * Rounding can carry past the last retained digit (9.99995 -> 10.0000). If
     * the increment were written assuming it stayed in place, that would become
     * 9.10000 or similar.
     */
    #[Test]
    public function rounding_carries_across_the_scale(): void
    {
        $this->assertSame('10.0000', (string) Money::ofTolerant('9.99995'));
        $this->assertSame('1.0000', (string) Money::ofTolerant('0.99995'));
    }

    #[Test]
    public function rounding_is_symmetric_around_zero(): void
    {
        $this->assertSame('-0.0001', (string) Money::ofTolerant('-0.00005'));
    }

    #[Test]
    #[DataProvider('malformedAmounts')]
    public function it_rejects_malformed_amounts(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of($value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedAmounts(): array
    {
        return [
            'letters' => ['abc'],
            'a lone sign' => ['-'],
            'a lone decimal point' => ['.'],
            'trailing letters' => ['100usd'],
            'two decimal points' => ['1.2.3'],
        ];
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $original = Money::of('100.00');
        $original->plus(Money::of('50.00'));

        $this->assertSame('100.0000', (string) $original);
    }

    #[Test]
    public function it_multiplies_by_a_whole_number(): void
    {
        $this->assertSame('37.5000', (string) Money::of('12.50')->timesInt(3));
        $this->assertSame('0.0000', (string) Money::of('12.50')->timesInt(0));
        $this->assertSame('-37.5000', (string) Money::of('12.50')->timesInt(-3));
    }
}
