<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = Str::upper($this->faker->unique()->regexify('[A-Z]{3}'));

        return [
            /*
             * There is no seeder in this project, per the Phase 14 brief, so a test
             * that needs a currency creates one through this factory. That makes the
             * factory the place a new project's first currencies appear - which is
             * the honest consequence of the no-seeder rule and worth stating rather
             * than hiding behind a fixture list.
             */
            'code' => $code,
            'name' => Str::title($this->faker->words(2, true)),
            'symbol' => $this->faker->randomElement(['$', '€', '£', '¥', '₹', 'R']),
            'decimal_precision' => 2,
            'is_active' => true,
        ];
    }

    /**
     * A currency with no decimal places in its minor unit - JPY, KRW, VND.
     *
     * The interesting case for a multi-currency system, because offering to book a
     * third of a yen is a rounding decision the user never agreed to.
     */
    public function noMinorUnits(): static
    {
        return $this->state(fn () => ['decimal_precision' => 0]);
    }

    /**
     * A currency with three decimal places - BHD, JOD, KWD, OMR.
     */
    public function threeMinorUnits(): static
    {
        return $this->state(fn () => ['decimal_precision' => 3]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function code(string $code): static
    {
        return $this->state(fn () => ['code' => Str::upper($code)]);
    }
}
