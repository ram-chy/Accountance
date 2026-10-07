<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),

            /*
             * The pair is two fresh currencies rather than a lookup, because
             * exchange_rates has no natural "one obvious" pair - which currencies a
             * test needs depends entirely on what the test is about. A factory that
             * always produced USD/EUR would quietly impose a base currency on every
             * test that used it, and a test asserting "the rate for the company's own
             * base currency pair" would then be testing its own factory.
             */
            'from_currency_id' => Currency::factory(),
            'to_currency_id' => Currency::factory(),

            'effective_date' => Carbon::today()->toDateString(),

            /*
             * A positive rate with a plausible magnitude. Not 1, because a rate of
             * exactly 1 would make a test that forgot to set a rate pass silently -
             * the conversion would be the identity and the assertion could not tell
             * it apart from no conversion happening at all.
             */
            'rate' => '2.5000000000',

            'source' => 'Test Factory',
            'is_active' => true,
        ];
    }

    /**
     * A specific pair, by currency code.
     */
    public function pair(string $fromCode, string $toCode): static
    {
        return $this->state(fn () => [
            'from_currency_id' => Currency::factory()->code($fromCode),
            'to_currency_id' => Currency::factory()->code($toCode),
        ]);
    }

    public function onDate(Carbon|string $date): static
    {
        return $this->state(fn () => [
            'effective_date' => $date instanceof Carbon
                ? $date->toDateString()
                : Carbon::parse($date)->toDateString(),
        ]);
    }

    /**
     * A quoted rate. Named so the test says what it means at the call site.
     */
    public function quoting(string $rate): static
    {
        return $this->state(fn () => ['rate' => $rate]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
