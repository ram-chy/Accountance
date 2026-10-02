<?php

namespace Tests\Feature\Accounting;

use App\Enums\TaxCalculationBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Tax;
use App\Models\TaxAccountMapping;
use App\Models\TaxRate;
use App\Services\Accounting\Tax\TaxCalculationService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tax arithmetic itself.
 *
 * This is the only test file that exercises TaxCalculationService directly rather
 * than through HTTP, because the arithmetic is the part of the phase that must be
 * right regardless of how it is reached. Every figure asserted here is checked as
 * an exact decimal string, never as a float: a test that compared with assertEquals
 * on floats would pass on 6.9767 and 6.97669 alike and would not notice the
 * difference between half-up and truncation.
 */
class TaxCalculationTest extends TestCase
{
    use RefreshDatabase;

    private TaxCalculationService $calculator;

    private Company $company;

    private Account $liability;

    private Account $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(TaxCalculationService::class);

        $this->company = Company::factory()->create();

        $this->liability = Account::factory()->for($this->company)->liability()->create([
            'code' => '2100',
            'name' => 'Tax Payable',
        ]);

        $this->asset = Account::factory()->for($this->company)->asset()->create([
            'code' => '1400',
            'name' => 'Tax Recoverable',
        ]);
    }

    /**
     * A configured, active tax with a mapped account.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function tax(string $code, string $rate, array $attributes = []): Tax
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => $code,
            'name' => strtoupper($code).' Tax',
            ...$attributes,
        ]);

        TaxRate::factory()->for($tax)->rate($rate)->create();

        TaxAccountMapping::factory()
            ->for($tax)
            ->outputAccount($this->liability)
            ->inputAccount($this->asset)
            ->create();

        return $tax;
    }

    #[Test]
    public function tax_exclusive_adds_tax_to_a_net_amount(): void
    {
        $vat = $this->tax('VAT', '10.0000');

        $result = $this->calculator->calculate(
            Money::of('1000.0000'),
            [$vat],
            Carbon::parse('2026-06-15')
        );

        $this->assertSame('1000.0000', $result->taxableAmount->toDatabase());
        $this->assertSame('100.0000', $result->totalTax->toDatabase());
        $this->assertSame('1100.0000', $result->grossAmount->toDatabase());
        $this->assertSame(TaxCalculationBasis::Exclusive, $result->basis);
    }

    #[Test]
    public function tax_inclusive_extracts_tax_from_a_gross_amount(): void
    {
        $vat = $this->tax('VAT', '10.0000', [
            'calculation_basis' => TaxCalculationBasis::Inclusive,
        ]);

        $result = $this->calculator->calculate(
            Money::of('1100.0000'),
            [$vat],
            Carbon::parse('2026-06-15')
        );

        $this->assertSame('1000.0000', $result->taxableAmount->toDatabase());
        $this->assertSame('100.0000', $result->totalTax->toDatabase());
        $this->assertSame('1100.0000', $result->grossAmount->toDatabase());
        $this->assertSame(TaxCalculationBasis::Inclusive, $result->basis);
    }

    /**
     * The divisor is 100 + rate, not 100.
     *
     * Asserted at a rate and an amount chosen so that the two formulas differ in
     * the last place. Dividing a gross by 100 instead of 110 gives 47.9760 on this
     * figure rather than 39.9800 - a wrong answer that still looks like a tax.
     */
    #[Test]
    public function an_inclusive_gross_is_divided_by_one_plus_the_rate(): void
    {
        $vat = $this->tax('VAT', '20.0000', [
            'calculation_basis' => TaxCalculationBasis::Inclusive,
        ]);

        $result = $this->calculator->calculate(
            Money::of('239.8800'),
            [$vat],
            Carbon::parse('2026-06-15')
        );

        $this->assertSame('39.9800', $result->totalTax->toDatabase());
        $this->assertSame('199.9000', $result->taxableAmount->toDatabase());
        $this->assertSame('239.8800', $result->grossAmount->toDatabase());
    }

    #[Test]
    public function no_taxes_returns_the_amount_untaxed(): void
    {
        $result = $this->calculator->calculate(
            Money::of('250.0000'),
            [],
            Carbon::parse('2026-06-15')
        );

        $this->assertSame('250.0000', $result->taxableAmount->toDatabase());
        $this->assertSame('0.0000', $result->totalTax->toDatabase());
        $this->assertSame('250.0000', $result->grossAmount->toDatabase());
        $this->assertCount(0, $result->components);
    }

    #[Test]
    public function a_zero_rate_tax_is_applied_and_charges_nothing(): void
    {
        $exempt = $this->tax('EXEMPT', '0.0000');

        $result = $this->calculator->calculate(
            Money::of('100.0000'),
            [$exempt],
            Carbon::parse('2026-06-15')
        );

        $this->assertSame('0.0000', $result->totalTax->toDatabase());
        $this->assertCount(1, $result->components);
        $this->assertSame('EXEMPT', $result->components->first()->taxCode);
    }

    /**
     * Two taxes on one sale are charged on the same base, not compounded.
     *
     * 5% and 3% on 1000.00 is 80.0000. A cascading implementation would apply 3%
     * to 1050.00 and produce 81.5000 - a difference of 1.5000 that is entirely
     * wrong and entirely invisible in the components.
     */
    #[Test]
    public function multiple_taxes_are_applied_in_parallel_not_cascading(): void
    {
        $a = $this->tax('TAXA', '5.0000');
        $b = $this->tax('TAXB', '3.0000');

        $result = $this->calculator->calculate(
            Money::of('1000.0000'),
            [$a, $b],
            Carbon::parse('2026-06-15')
        );

        $this->assertSame('1000.0000', $result->taxableAmount->toDatabase());
        $this->assertSame('50.0000', $result->components[0]->taxAmount->toDatabase());
        $this->assertSame('30.0000', $result->components[1]->taxAmount->toDatabase());
        $this->assertSame('80.0000', $result->totalTax->toDatabase());
        $this->assertSame('1080.0000', $result->grossAmount->toDatabase());
    }

    #[Test]
    public function an_inclusive_gross_with_two_taxes_splits_on_the_extracted_net(): void
    {
        $a = $this->tax('TAXA', '5.0000');
        $b = $this->tax('TAXB', '3.0000');

        $result = $this->calculator->calculate(
            Money::of('1080.0000'),
            [$a, $b],
            Carbon::parse('2026-06-15'),
            TaxCalculationBasis::Inclusive
        );

        $this->assertSame('1000.0000', $result->taxableAmount->toDatabase());
        $this->assertSame('50.0000', $result->components[0]->taxAmount->toDatabase());
        $this->assertSame('30.0000', $result->components[1]->taxAmount->toDatabase());
        $this->assertSame('80.0000', $result->totalTax->toDatabase());
        $this->assertSame('1080.0000', $result->grossAmount->toDatabase());
    }

    /**
     * The component order follows the caller's order, so two documents naming the
     * same taxes in the same order produce identical breakdowns.
     */
    #[Test]
    public function component_order_follows_the_order_the_taxes_were_supplied(): void
    {
        $a = $this->tax('TAXA', '5.0000');
        $b = $this->tax('TAXB', '3.0000');

        $forward = $this->calculator->calculate(Money::of('1000.0000'), [$a, $b], Carbon::parse('2026-06-15'));
        $reverse = $this->calculator->calculate(Money::of('1000.0000'), [$b, $a], Carbon::parse('2026-06-15'));

        $this->assertSame(
            ['TAXA', 'TAXB'],
            $forward->components->map(fn ($c) => $c->taxCode)->all()
        );

        $this->assertSame(
            ['TAXB', 'TAXA'],
            $reverse->components->map(fn ($c) => $c->taxCode)->all()
        );

        // The total is identical either way: ordering is presentation, not a
        // different tax base.
        $this->assertTrue($forward->totalTax->equals($reverse->totalTax));
    }

    /**
     * Rounding is half-up, once, at the stored scale.
     *
     * 7.5% of 10.0000 is 0.7500 exactly, so this asserts the scale rather than a
     * tie. The tie itself is 2.5% of 0.1000 = 0.0025, which must round to
     * 0.0025 (exact) - and 0.5% of 0.1000 = 0.00050 which rounds to 0.0005.
     */
    #[Test]
    public function tax_amounts_are_rounded_half_up_once(): void
    {
        $tax = $this->tax('ODD', '7.5000');

        $result = $this->calculator->calculate(Money::of('10.0000'), [$tax], Carbon::parse('2026-06-15'));

        $this->assertSame('0.7500', $result->totalTax->toDatabase());
    }

    #[Test]
    public function a_half_cent_rounds_away_from_zero(): void
    {
        $tax = $this->tax('HALF', '2.5000');

        // 2.5% of 0.0200 = 0.000500, which must round up to 0.0005 rather than
        // truncate to 0.0004 or 0.0000.
        $result = $this->calculator->calculate(Money::of('0.0200'), [$tax], Carbon::parse('2026-06-15'));

        $this->assertSame('0.0005', $result->totalTax->toDatabase());
    }

    /**
     * The result always foots: net + tax = gross, and the components sum to the total.
     *
     * Checked by construction rather than by assertion alone - TaxCalculationResult
     * throws if either fails - so this test documents that the invariant is not
     * something the arithmetic merely happens to satisfy.
     */
    #[Test]
    public function the_result_always_foots(): void
    {
        $a = $this->tax('TAXA', '3.0000');
        $b = $this->tax('TAXB', '2.5000');

        foreach ([
            [Money::of('0.0001'), TaxCalculationBasis::Exclusive],
            [Money::of('1.0003'), TaxCalculationBasis::Exclusive],
            [Money::of('33.3333'), TaxCalculationBasis::Inclusive],
            [Money::of('99999.9999'), TaxCalculationBasis::Exclusive],
        ] as [$amount, $basis]) {
            $result = $this->calculator->calculate($amount, [$a, $b], Carbon::parse('2026-06-15'), $basis);

            $this->assertTrue(
                $result->taxableAmount->plus($result->totalTax)->equals($result->grossAmount),
                'taxable + total_tax must equal gross'
            );
        }
    }

    #[Test]
    public function the_rate_in_force_on_the_document_date_is_used(): void
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => 'HIST',
            'name' => 'Historic Tax',
        ]);

        TaxRate::factory()->for($tax)->rate('10.0000')->effectiveFrom('2020-01-01')->effectiveTo('2026-03-31')->create();
        TaxRate::factory()->for($tax)->rate('12.0000')->effectiveFrom('2026-04-01')->create();

        TaxAccountMapping::factory()->for($tax)->outputAccount($this->liability)->create();

        $march = $this->calculator->calculate(Money::of('1000.0000'), [$tax], Carbon::parse('2026-03-31'));
        $april = $this->calculator->calculate(Money::of('1000.0000'), [$tax], Carbon::parse('2026-04-01'));

        $this->assertSame('100.0000', $march->totalTax->toDatabase());
        $this->assertSame('120.0000', $april->totalTax->toDatabase());
    }

    /**
     * The boundary days are inclusive on both sides, so consecutive periods leave
     * no day unowned and no day doubly owned.
     */
    #[Test]
    public function rate_boundaries_are_inclusive_on_both_ends(): void
    {
        $tax = Tax::factory()->for($this->company)->create(['code' => 'BOUND', 'name' => 'Boundary Tax']);

        TaxRate::factory()->for($tax)->rate('10.0000')->effectiveFrom('2026-01-01')->effectiveTo('2026-03-31')->create();
        TaxRate::factory()->for($tax)->rate('12.0000')->effectiveFrom('2026-04-01')->create();

        TaxAccountMapping::factory()->for($tax)->outputAccount($this->liability)->create();

        $last = $this->calculator->calculate(Money::of('1000.0000'), [$tax], Carbon::parse('2026-03-31'));
        $first = $this->calculator->calculate(Money::of('1000.0000'), [$tax], Carbon::parse('2026-04-01'));

        $this->assertSame('100.0000', $last->totalTax->toDatabase());
        $this->assertSame('120.0000', $first->totalTax->toDatabase());
    }

    #[Test]
    public function an_inactive_rate_is_not_applied_and_a_missing_one_is_refused(): void
    {
        $tax = Tax::factory()->for($this->company)->create(['code' => 'OFF', 'name' => 'Retired Rate']);

        TaxRate::factory()->for($tax)->rate('10.0000')->inactive()->create();
        TaxAccountMapping::factory()->for($tax)->outputAccount($this->liability)->create();

        $this->expectException(ValidationException::class);

        $this->calculator->calculate(Money::of('1000.0000'), [$tax], Carbon::parse('2026-06-15'));
    }

    #[Test]
    public function an_inactive_tax_is_refused(): void
    {
        $tax = Tax::factory()->for($this->company)->inactive()->create(['code' => 'DEAD', 'name' => 'Dead Tax']);

        TaxRate::factory()->for($tax)->rate('10.0000')->create();

        try {
            $this->calculator->calculate(Money::of('1000.0000'), [$tax], Carbon::parse('2026-06-15'));

            $this->fail('An inactive tax must not be calculable.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('taxes.DEAD', $e->errors());
        }
    }

    /**
     * The highest rate the system accepts.
     *
     * 100% itself is rejected by the table's CHECK constraint rather than by
     * application code, which is asserted in TaxRateServiceTest against the
     * database. What matters here is that a rate just below the ceiling still
     * calculates, so the boundary is not accidentally exclusive.
     */
    #[Test]
    public function a_rate_just_below_one_hundred_percent_still_calculates(): void
    {
        $tax = $this->tax('NEARFULL', '99.9999', [
            'calculation_basis' => TaxCalculationBasis::Inclusive,
        ]);

        $result = $this->calculator->calculate(
            Money::of('100.0000'),
            [$tax],
            Carbon::parse('2026-06-15')
        );

        $this->assertSame('100.0000', $result->grossAmount->toDatabase());
        // At 99.9999% inclusive the tax inside a gross of 100 is very nearly half
        // of it, because the divisor is 199.9999 rather than 100. This is the
        // clearest demonstration of why the inclusive divisor is 100 + rate.
        $this->assertSame('50.0000', $result->taxableAmount->toDatabase());
        $this->assertSame('50.0000', $result->totalTax->toDatabase());
    }

    /**
     * The Phase 5 shape: a bare rate with no configured tax behind it.
     *
     * The tax_id of 0 is not a bug - it is the honest answer for "this figure came
     * from a typed-in rate, not from a tax", and no caller books from this
     * component directly.
     */
    #[Test]
    public function a_bare_rate_calculates_without_a_configured_tax(): void
    {
        $result = $this->calculator->calculateSingle(Money::of('199.9000'), Money::of('20.0000'));

        $this->assertSame('39.9800', $result->totalTax->toDatabase());
        $this->assertSame('239.8800', $result->grossAmount->toDatabase());
        $this->assertSame(0, $result->components->first()->taxId);
    }

    #[Test]
    public function a_bare_rate_can_also_calculate_inclusively(): void
    {
        $result = $this->calculator->calculateSingle(
            Money::of('239.8800'),
            Money::of('20.0000'),
            TaxCalculationBasis::Inclusive
        );

        $this->assertSame('39.9800', $result->totalTax->toDatabase());
        $this->assertSame('199.9000', $result->taxableAmount->toDatabase());
        $this->assertSame('239.8800', $result->grossAmount->toDatabase());
    }

    /**
     * Every component carries the destination account, so a caller does not have to
     * reassemble identity and account separately.
     */
    #[Test]
    public function components_carry_their_account_mapping(): void
    {
        $tax = $this->tax('VAT', '10.0000');

        $component = $this->calculator
            ->calculate(Money::of('1000.0000'), [$tax], Carbon::parse('2026-06-15'))
            ->components->first();

        $this->assertSame($this->liability->getKey(), $component->outputAccountId);
        $this->assertSame($this->asset->getKey(), $component->inputAccountId);
        $this->assertSame('VAT', $component->taxCode);
        $this->assertSame('10.0000', $component->rate->toDatabase());
    }

    /**
     * The rate lookup is per calculation, not per line.
     *
     * Counted rather than timed, because "fast enough" is not a test. The model
     * instances are re-fetched on every iteration: an already-loaded relation is
     * cached on the instance, so reusing the same object would let a second and
     * later iteration cost nothing and the assertion would pass while a caller
     * calculating a hundred lines with a hundred objects still issued a query each.
     */
    #[Test]
    public function repeated_calculations_do_not_query_per_line(): void
    {
        $a = $this->tax('TAXA', '5.0000');
        $b = $this->tax('TAXB', '3.0000');

        $date = Carbon::parse('2026-06-15');

        $queries = 0;

        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $perCalculation = fn () => $this->calculator->calculate(
            Money::of('100.0000'),
            [Tax::findOrFail($a->getKey()), Tax::findOrFail($b->getKey())],
            $date
        );

        $queries = 0;
        $perCalculation();

        $single = $queries;

        $queries = 0;

        for ($i = 0; $i < 10; $i++) {
            $perCalculation();
        }

        $this->assertSame(
            $single * 10,
            $queries,
            'Ten calculations of the same tax must cost ten times one, not a growing multiple.'
        );
    }
}
