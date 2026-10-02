<?php

namespace App\Services\Accounting\Tax;

use App\Enums\TaxCalculationBasis;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The tax arithmetic. Nothing else in the application performs any.
 *
 * Every figure this phase produces on a document comes from here, including the
 * single-rate case the Phase 5 flows already had. That is not tidiness - it is
 * the only way a later change to tax behaviour cannot land on one code path and
 * miss the other, leaving the engine correct in the API and wrong on invoices.
 *
 * THE TWO DIRECTIONS
 *
 *   EXCLUSIVE  the caller holds the net. tax = net x rate / 100, gross = net + tax.
 *   INCLUSIVE  the caller holds the gross. tax = gross x rate / (100 + rate),
 *              net = gross - tax.
 *
 * The inclusive divisor is the whole difficulty. It is 100 + rate, not 100, and
 * `Money::inclusivePercentageOf()` exists so that division happens inside the
 * value object at a documented working scale rather than being spelled out in
 * float at a call site.
 *
 * MULTIPLE TAXES ARE PARALLEL, NEVER CASCADING
 *
 * Two taxes on one sale - a state and a county rate, or a central and a state
 * GST - are both charged on the same base. Neither is charged on the amount that
 * includes the other. Compounding them would be a different tax system, and one
 * this application does not model: a jurisdiction that genuinely cascades has a
 * different base for each component, which is a rule about the *base*, not about
 * the order of application, and guessing at it would produce a plausible invoice
 * with the wrong total.
 *
 * So for an exclusive calculation every component is `taxable x rate_i / 100`,
 * all from the same `taxable`. For an inclusive calculation the single unknown is
 * `taxable`, and it is solved once from the combined rate:
 *
 *     gross = taxable x (1 + SUM(rate_i / 100))
 *     taxable = gross / (1 + SUM(rate_i / 100))
 *
 * then each component is `taxable x rate_i / 100`, exactly as in the exclusive
 * case. The net is therefore extracted once and the components are ordinary
 * exclusive computations on it - one code path for the components, whichever
 * direction the caller arrived from.
 *
 * ROUNDING, AND THE RESIDUAL
 *
 * Each component is rounded once, half-up, to the stored scale. Summing N
 * rounded components can disagree with the difference the caller supplied: three
 * components on a taxable of 0.0001 can each round to 0.0000 while their exact
 * sum would not. The document must still foot - `TaxableAmount + totalTax ==
 * grossAmount` is a hard invariant of `TaxCalculationResult` - so the residual,
 * at most N-1 units in the last place, is added to the largest component.
 *
 * The largest component is chosen deliberately rather than the first: the
 * adjustment is bounded by half a cent whichever is chosen, but putting it on the
 * smallest component could turn a zero-rate or sub-cent tax into a figure that
 * disagrees with its own rate, which is the kind of discrepancy that is
 * impossible to explain to a tax authority. Assigning it to the largest keeps the
 * relative error at its smallest and never invents a tax that was not charged.
 */
class TaxCalculationService
{
    /**
     * Calculate the taxes on an amount.
     *
     * @param  iterable<Tax>  $taxes  the configured taxes to apply, in the order they should be applied
     * @param  Carbon|string  $date  the document date, which decides each tax's effective rate
     * @param  TaxCalculationBasis|null  $basis  how to read $amount; null takes the first tax's configured basis
     * @param  string  $field  the request field these errors belong to, so the API can point at it
     *
     * @throws ValidationException when a tax has no rate in force on the date, or its stored rate is unusable
     */
    public function calculate(
        Money $amount,
        iterable $taxes,
        Carbon|string $date,
        ?TaxCalculationBasis $basis = null,
        string $field = 'taxes',
    ): TaxCalculationResult {
        $taxes = Collection::make($taxes)->values();

        if ($taxes->isEmpty()) {
            return TaxCalculationResult::untaxed($amount, $basis ?? TaxCalculationBasis::Exclusive);
        }

        $basis ??= $taxes->first()->calculation_basis;

        /*
         * Resolved before any arithmetic, so a tax that cannot be applied at all
         * is refused in full rather than contributing a zero component. A document
         * silently calculating without a tax the user asked for is worse than a
         * validation error naming it.
         */
        $resolved = $this->resolveRates($taxes, $date, $field);

        $taxable = $amount;

        if ($basis === TaxCalculationBasis::Inclusive) {
            /*
             * gross = taxable x (1 + SUM(rate_i) / 100), so the one unknown is the
             * net and it is solved from the combined rate. Divided by 100 rather
             * than summed and divided afterwards because the stored scale is 4 and
             * a rate of 12.5% is 0.1250 exactly - the division loses nothing.
             *
             * This is the only place a multiplier is used as a Money. It is a
             * divisor, not an amount, and wrapping it keeps the division inside
             * Money::dividedBy() so the working scale and the single rounding
             * stay the ones the class documents.
             */
            $combinedFactor = $resolved->reduce(
                fn (Money $carry, ResolvedTaxRate $tax) => $carry->plus($tax->rateAmount),
                Money::ofInt(100)
            );

            $taxable = $amount->times(Money::ofInt(100))->dividedBy($combinedFactor);
        }

        $components = $resolved
            ->map(fn (ResolvedTaxRate $rate) => $this->component($rate, $taxable))
            ->values();

        /*
         * The adjustment is applied to the components before the result is built,
         * not after: the result refuses to exist unless it foots, so a caller can
         * never be handed a tax breakdown that does not add up.
         *
         * Only the inclusive direction has a figure to absorb towards. There the
         * caller quoted a gross, and the net was solved from it, so the components
         * must add back up to what was quoted. The exclusive direction has no such
         * constraint - its components are independently exact on a known net, and
         * there is no quoted total to disagree with.
         */
        if ($basis === TaxCalculationBasis::Inclusive) {
            $this->absorbResidual($components, $amount->minus($taxable));
        }

        return new TaxCalculationResult(
            taxableAmount: $basis === TaxCalculationBasis::Inclusive ? $taxable : $amount,
            components: $components,
            totalTax: $components->reduce(
                fn (Money $carry, TaxComponentResult $component) => $carry->plus($component->taxAmount),
                Money::zero()
            ),
            grossAmount: $taxable->plus(
                $components->reduce(
                    fn (Money $carry, TaxComponentResult $component) => $carry->plus($component->taxAmount),
                    Money::zero()
                )
            ),
            basis: $basis,
        );
    }

    /**
     * Calculate the taxes on an amount, reading it as net or gross per the first
     * tax's own configuration.
     *
     * A convenience over calculate() with the basis defaulted, for the common
     * case where the caller has a configured tax in hand and wants its basis
     * honoured without reading the column.
     */
    public function calculateUsingConfiguredBasis(Money $amount, iterable $taxes, Carbon|string $date, string $field = 'taxes'): TaxCalculationResult
    {
        return $this->calculate($amount, $taxes, $date, null, $field);
    }

    /**
     * Calculate one tax's share of an amount.
     *
     * The shape the Phase 5 document flows use, where a line carries a rate rather
     * than a tax: the rate is already resolved and already snapshotted onto the
     * line, so the only question left is the arithmetic.
     *
     * @throws ValidationException when the rate cannot produce a defined tax
     */
    public function calculateSingle(
        Money $taxable,
        Money $rate,
        ?TaxCalculationBasis $basis = null,
        string $field = 'tax_rate',
    ): TaxCalculationResult {
        $this->assertUsableRate($rate, $field);

        $basis ??= TaxCalculationBasis::Exclusive;

        if ($basis === TaxCalculationBasis::Inclusive) {
            $tax = $taxable->inclusivePercentageOf($rate);
            $net = $taxable->minus($tax);

            return new TaxCalculationResult(
                taxableAmount: $net,
                components: Collection::make([$this->unattachedComponent($rate, $net, $tax)]),
                totalTax: $tax,
                grossAmount: $net->plus($tax),
                basis: $basis,
            );
        }

        $tax = $taxable->percentageOf($rate);

        return new TaxCalculationResult(
            taxableAmount: $taxable,
            components: Collection::make([$this->unattachedComponent($rate, $taxable, $tax)]),
            totalTax: $tax,
            grossAmount: $taxable->plus($tax),
            basis: $basis,
        );
    }

    /**
     * Look up each tax's effective rate and reject anything that cannot be applied.
     *
     * @param  Collection<int, Tax>  $taxes
     * @return Collection<int, ResolvedTaxRate>
     */
    private function resolveRates(Collection $taxes, Carbon|string $date, string $field): Collection
    {
        /*
         * Every rate for every candidate in one query. A document with ten lines
         * each carrying two taxes would otherwise issue twenty rate lookups to
         * answer one question, and the numbers come from the same table.
         */
        $ratesByTax = TaxRate::query()
            ->whereIn('tax_id', $taxes->pluck('id'))
            ->where('is_active', true)
            ->orderBy('effective_from')
            ->get()
            ->groupBy('tax_id');

        $resolved = Collection::make();
        $errors = [];

        foreach ($taxes as $tax) {
            if (! $tax->is_active) {
                $errors["{$field}.{$tax->code}"] = "Tax [{$tax->code}] is not active.";
            }

            $rate = $tax->rateOn($date, $ratesByTax->get($tax->id));

            if ($rate === null) {
                $errors["{$field}.{$tax->code}"] = "Tax [{$tax->code}] has no rate in force on {$this->day($date)}.";
            }

            $resolved->push(new ResolvedTaxRate(
                tax: $tax,
                rate: $rate,
                rateAmount: $rate?->rateAmount() ?? Money::zero(),
            ));
        }

        $errors += $this->unusableRateErrors($resolved, $field);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * One component of a multi-tax calculation.
     */
    private function component(ResolvedTaxRate $resolved, Money $taxable): TaxComponentResult
    {
        $mapping = $resolved->tax->accountMapping;

        return new TaxComponentResult(
            taxId: $resolved->tax->id,
            taxCode: $resolved->tax->code,
            taxName: $resolved->tax->name,
            taxType: $resolved->tax->tax_type->value,
            taxRateId: $resolved->rate?->id,
            rate: $resolved->rateAmount,
            taxableAmount: $taxable,
            taxAmount: $taxable->percentageOf($resolved->rateAmount),
            outputAccountId: $mapping?->output_account_id,
            inputAccountId: $mapping?->input_account_id,
        );
    }

    /**
     * A component for a bare rate, with no configured tax behind it.
     *
     * The Phase 5 shape: a line has a typed-in rate and no tax configuration, so
     * there is nothing to point an account at. The zeros are honest - there is no
     * configured destination - and no caller books from this component directly;
     * `DocumentCalculator` reads only the total.
     */
    private function unattachedComponent(Money $rate, Money $taxable, Money $tax): TaxComponentResult
    {
        return new TaxComponentResult(
            taxId: 0,
            taxCode: '',
            taxName: '',
            taxType: '',
            taxRateId: null,
            rate: $rate,
            taxableAmount: $taxable,
            taxAmount: $tax,
            outputAccountId: null,
            inputAccountId: null,
        );
    }

    /**
     * Force the components to sum to the tax the caller supplied, by adjusting the
     * largest one.
     *
     * @param  Collection<int, TaxComponentResult>  $components
     */
    private function absorbResidual(Collection $components, Money $expectedTotal): void
    {
        $actual = $components->reduce(
            fn (Money $carry, TaxComponentResult $component) => $carry->plus($component->taxAmount),
            Money::zero()
        );

        $residual = $expectedTotal->minus($actual);

        if ($residual->isZero() || $components->isEmpty()) {
            return;
        }

        /*
         * A residual wider than the number of components is not rounding - rounding
         * moves each component by at most half a unit in the last place, so the
         * components can differ from their exact sum by at most one unit each.
         * Anything larger means the net was solved at a precision that did not hold,
         * and quietly "fixing" it here would hide that behind a plausible-looking
         * invoice. Surfacing it as an error is the honest response; the situation
         * is not reachable with rates and amounts inside the configured scale, so
         * seeing it means something upstream changed.
         */
        $bound = Money::ofInt($components->count());

        if ($residual->absolute()->greaterThan($bound)) {
            throw ValidationException::withMessages([
                'taxes' => 'The tax components do not reconcile with the supplied amount.',
            ]);
        }

        $index = $components
            ->sortByDesc(fn (TaxComponentResult $component) => $component->taxAmount->toDatabase())
            ->keys()
            ->first();

        $adjusted = $components[$index]->taxAmount->plus($residual);

        $components[$index] = new TaxComponentResult(
            taxId: $components[$index]->taxId,
            taxCode: $components[$index]->taxCode,
            taxName: $components[$index]->taxName,
            taxType: $components[$index]->taxType,
            taxRateId: $components[$index]->taxRateId,
            rate: $components[$index]->rate,
            taxableAmount: $components[$index]->taxableAmount,
            taxAmount: $adjusted,
            outputAccountId: $components[$index]->outputAccountId,
            inputAccountId: $components[$index]->inputAccountId,
        );
    }

    /**
     * Reject a rate that cannot produce a defined tax, reporting every offender
     * rather than only the first.
     *
     * The upper bound matters specifically for the inclusive direction: at 100%
     * the divisor (100 + rate) is zero, and above it the "net" comes out
     * negative, which would book a negative tax on a positive sale. Rejecting at
     * the door is better than letting bcmath raise a division error that surfaces
     * as a 500.
     *
     * Collected rather than thrown from inside the loop so a document with three
     * unusable taxes reports three errors in one response.
     *
     * @param  Collection<int, ResolvedTaxRate>  $resolved
     * @return array<string, string>
     */
    private function unusableRateErrors(Collection $resolved, string $field): array
    {
        return $resolved
            ->filter(fn (ResolvedTaxRate $tax) => ! $this->isUsableRate($tax->rateAmount))
            ->mapWithKeys(fn (ResolvedTaxRate $tax) => [
                "{$field}.{$tax->tax->code}" => $tax->rateAmount->isNegative()
                    ? 'A tax rate cannot be negative.'
                    : 'A tax rate must be less than 100%.',
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function assertUsableRate(Money $rate, string $field): void
    {
        if ($this->isUsableRate($rate)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => $rate->isNegative()
                ? 'A tax rate cannot be negative.'
                : 'A tax rate must be less than 100%.',
        ]);
    }

    private function isUsableRate(Money $rate): bool
    {
        return ! $rate->isNegative() && $rate->lessThan(Money::ofInt(100));
    }

    private function day(Carbon|string $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : $date;
    }
}
