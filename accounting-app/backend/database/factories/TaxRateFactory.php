<?php

namespace Database\Factories;

use App\Models\Tax;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    /**
     * A single open-ended rate from 2020-01-01, which is effectively "always".
     *
     * Deliberately not randomised in effective_from/effective_to or rate. A rate is
     * a date-bounded fact, and a factory that picks a random one produces tests
     * that are correct about the tax arithmetic but accidentally correct about the
     * dates too - so a genuine off-by-one-day bug in `isEffectiveOn()` would still
     * pass. Tests that exercise history state their own dates.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_id' => Tax::factory(),
            'company_id' => fn (array $attributes) => Tax::findOrFail($attributes['tax_id'])->company_id,
            'rate' => '10.0000',
            'effective_from' => Carbon::parse('2020-01-01')->startOfDay(),
            'effective_to' => null,
            'is_active' => true,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function rate(string $rate): static
    {
        return $this->state(fn () => ['rate' => $rate]);
    }

    public function effectiveFrom(Carbon|string $date): static
    {
        return $this->state(fn () => ['effective_from' => Carbon::parse($date)->startOfDay()]);
    }

    public function effectiveTo(Carbon|string $date): static
    {
        return $this->state(fn () => ['effective_to' => Carbon::parse($date)->endOfDay()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
