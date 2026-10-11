<?php

namespace Database\Factories;

use App\Models\FinancialDimension;
use App\Models\FinancialDimensionValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FinancialDimensionValue>
 */
class FinancialDimensionValueFactory extends Factory
{
    protected $model = FinancialDimensionValue::class;

    /**
     * A value of a real dimension of the SAME company as any record it is attached
     * to. The dimension is resolved from the value's own parent rather than
     * created independently, for the same reason FixedAssetCategoryFactory derives
     * its accounts from its company: a value pointing at a dimension of some other
     * company is a row no endpoint would ever accept.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'financial_dimension_id' => FinancialDimension::factory(),
            'code' => Str::upper(Str::slug($name, '-')),
            'name' => ucfirst($name),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
