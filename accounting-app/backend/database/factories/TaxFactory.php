<?php

namespace Database\Factories;

use App\Enums\TaxCalculationBasis;
use App\Enums\TaxType;
use App\Models\Company;
use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

    /**
     * The tax_type and calculation_basis are NOT randomised here, though the rest
     * of the definition could be.
     *
     * A factory that invents a tax_type means a test asserting an output-tax
     * posting rule can fail because the factory rolled INPUT, which reads as a
     * bug in the rule. Both fields are domain choices a test makes deliberately, so
     * they have a usable default and are overridden when the test cares.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' Tax';

        return [
            'company_id' => Company::factory(),
            'code' => Str::upper(Str::slug($name)),
            'name' => $name,
            'description' => null,
            'tax_type' => TaxType::Output,
            'calculation_basis' => TaxCalculationBasis::Exclusive,
            'is_active' => true,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function output(): static
    {
        return $this->state(fn () => ['tax_type' => TaxType::Output]);
    }

    public function input(): static
    {
        return $this->state(fn () => ['tax_type' => TaxType::Input]);
    }

    public function both(): static
    {
        return $this->state(fn () => ['tax_type' => TaxType::Both]);
    }

    public function inclusive(): static
    {
        return $this->state(fn () => ['calculation_basis' => TaxCalculationBasis::Inclusive]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
