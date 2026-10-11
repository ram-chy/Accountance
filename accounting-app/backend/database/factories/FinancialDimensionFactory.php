<?php

namespace Database\Factories;

use App\Enums\FinancialDimensionType;
use App\Models\Company;
use App\Models\FinancialDimension;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FinancialDimension>
 */
class FinancialDimensionFactory extends Factory
{
    protected $model = FinancialDimension::class;

    /**
     * A cost centre by default, because that is the dimension the phase exists
     * for. The code is derived from a unique name so two dimensions of the same
     * type never collide on the (company_id, type, code) unique index - a factory
     * that tripped that index would fail a test for a reason unrelated to what it
     * was written to check.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'company_id' => Company::factory(),
            'type' => FinancialDimensionType::CostCenter->value,
            'code' => Str::upper(Str::slug($name, '-')),
            'name' => ucfirst($name),
            'is_active' => true,
        ];
    }

    public function type(FinancialDimensionType $type): static
    {
        return $this->state(fn () => ['type' => $type->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
