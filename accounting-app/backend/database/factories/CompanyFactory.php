<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' Ltd',
            'registration_number' => Str::upper(Str::random(10)),
            'tax_number' => Str::upper(Str::random(8)),
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+1555'.fake()->numerify('#######'),
            'website' => 'https://'.$name.'.example',
            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => null,
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country_code' => 'US',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'currency_id' => null,
            'is_active' => true,
        ];
    }

    /**
     * An inactive company, which can never become an active context.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
