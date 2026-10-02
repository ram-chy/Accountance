<?php

namespace App\Services\Accounting\Tax;

use App\Enums\TaxCalculationBasis;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What one tax calculation produced.
 *
 * The contract the whole phase is written against: a taxable amount, the taxes
 * applied to it, the total of those taxes, and the gross. Everything a document
 * flow needs to write its own totals and its journal lines is here, and nothing
 * about journals, accounts or persistence is.
 *
 * Three invariants hold for every instance, and they are the reason the object
 * exists rather than an array:
 *
 *   taxable_amount + total_tax == gross_amount   (EXCLUSIVE)
 *   gross_amount  - total_tax == taxable_amount   (INCLUSIVE)
 *   total_tax     == sum of the components' tax amounts
 *
 * All three are checked in the constructor. A tax result that does not foot is
 * not a tax result a document can post, and a class that can be constructed into
 * an unbalanced state is a class whose bug surfaces three layers away as a journal
 * that will not balance - which is the least legible place in this application to
 * find a rounding mistake.
 */
final readonly class TaxCalculationResult
{
    /**
     * @param  Collection<int, TaxComponentResult>  $components  in calculation order
     */
    public function __construct(
        public Money $taxableAmount,
        public Collection $components,
        public Money $totalTax,
        public Money $grossAmount,
        public TaxCalculationBasis $basis,
    ) {
        if (! $this->taxableAmount->plus($this->totalTax)->equals($this->grossAmount)) {
            throw new \InvalidArgumentException(
                'A tax calculation result must satisfy taxable_amount + total_tax = gross_amount.'
            );
        }

        $componentTotal = $this->components->reduce(
            fn (Money $carry, TaxComponentResult $component) => $carry->plus($component->taxAmount),
            Money::zero()
        );

        if (! $componentTotal->equals($this->totalTax)) {
            throw new \InvalidArgumentException(
                'A tax calculation total_tax must equal the sum of its components.'
            );
        }
    }

    /**
     * A result with no taxes at all: the amount is its own gross.
     *
     * Used for a document line with no tax, so callers never have to branch on
     * "was there a result" to find out that the tax is zero.
     */
    public static function untaxed(Money $amount, TaxCalculationBasis $basis = TaxCalculationBasis::Exclusive): self
    {
        return new self(
            taxableAmount: $amount,
            components: new Collection,
            totalTax: Money::zero(),
            grossAmount: $amount,
            basis: $basis,
        );
    }

    /**
     * The taxes of a given type, in calculation order.
     *
     * @return Collection<int, TaxComponentResult>
     */
    public function outputComponents(): Collection
    {
        return $this->components->filter(
            fn (TaxComponentResult $c) => $c->taxType === 'OUTPUT' || $c->taxType === 'BOTH'
        )->values();
    }

    /**
     * @return Collection<int, TaxComponentResult>
     */
    public function inputComponents(): Collection
    {
        return $this->components->filter(
            fn (TaxComponentResult $c) => $c->taxType === 'INPUT' || $c->taxType === 'BOTH'
        )->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'basis' => $this->basis->value,
            'taxable_amount' => $this->taxableAmount->toDatabase(),
            'total_tax' => $this->totalTax->toDatabase(),
            'gross_amount' => $this->grossAmount->toDatabase(),
            'tax_components' => $this->components
                ->map(fn (TaxComponentResult $c) => $c->toArray())
                ->all(),
        ];
    }
}
