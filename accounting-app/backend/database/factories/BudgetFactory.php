<?php

namespace Database\Factories;

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\Company;
use App\Models\FinancialYear;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    /**
     * The default budget is a DRAFT for the current calendar year, in a company
     * of its own. A year is always created because a budget without one has no
     * periods to hold lines and is not a valid budget in this system.
     *
     * `status` is DRAFT and `version_number` is 1 because almost every test that
     * uses this factory is exercising the normal, not-yet-approved state; the
     * approved() state below flips it for the tests that need the immutable one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->startOfYear();
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'company_id' => Company::factory(),
            'financial_year_id' => fn (array $attributes) => FinancialYear::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->create()
                ->getKey(),
            'code' => Str::upper(fake()->unique()->bothify('BUDG-####')),
            'name' => $name,
            'version_number' => 1,
            'parent_budget_id' => null,
            'status' => BudgetStatus::Draft,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->getKey()]);
    }

    public function forFinancialYear(FinancialYear $year): static
    {
        return $this->state(fn () => [
            'company_id' => $year->company_id,
            'financial_year_id' => $year->getKey(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => BudgetStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function version(int $number): static
    {
        return $this->state(fn () => ['version_number' => $number]);
    }
}
