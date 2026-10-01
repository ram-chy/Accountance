<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),

            /*
             * A counter rather than fake()->unique()->numerify(): a test that
             * creates several customers in one company must not collide on the
             * per-company unique code, and unique() state is shared across the
             * whole test run rather than per company.
             */
            'customer_code' => 'CUST-'.fake()->unique()->numerify('######'),
            'name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'country_code' => 'US',
            'tax_identifier' => null,

            /*
             * The receivable account is created for the SAME company the
             * customer belongs to. Getting this wrong is the classic way a
             * factory produces a row the application would refuse to create, so
             * the two are derived from one expression rather than two.
             */
            'receivable_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::find($attributes['company_id']))
                ->asset()
                ->create(['code' => '1100', 'name' => 'Accounts Receivable'])
                ->getKey(),

            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
