<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'supplier_code' => 'SUP-'.fake()->unique()->numerify('######'),
            'name' => fake()->company(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'country_code' => 'US',
            'tax_identifier' => null,
            'payable_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::find($attributes['company_id']))
                ->liability()
                ->create(['code' => '2000', 'name' => 'Accounts Payable'])
                ->getKey(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
