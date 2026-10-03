<?php

namespace Database\Factories;

use App\Enums\DepreciationMethod;
use App\Models\Account;
use App\Models\Company;
use App\Models\FixedAssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FixedAssetCategory>
 */
class FixedAssetCategoryFactory extends Factory
{
    protected $model = FixedAssetCategory::class;

    /**
     * Every account reference is created here rather than defaulted to an id.
     *
     * A factory that returned bare integers would produce rows the service refuses -
     * accounts belonging to some other company, or with the wrong type - and a test
     * would then fail with an account validation error while appearing to be testing
     * something else. Building them through Account::factory() with the states that
     * match each ROLE means a category produced by this factory is one the service
     * will accept, so a test that only overrides the field it cares about still
     * exercises the path it means to.
     *
     * The accumulated depreciation account is contra() - an ASSET with a CREDIT
     * normal balance - because that is not a preference but a requirement:
     * TransactionAccountResolver::accumulatedDepreciation() refuses anything else, and
     * a debit-normal account here would make every test that uses this factory fail
     * for a reason unrelated to what it was written to check.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'company_id' => Company::factory(),
            'code' => Str::upper(Str::slug($name)),
            'name' => $name,
            'description' => null,
            'useful_life_months' => 60,
            'depreciation_method' => DepreciationMethod::StraightLine,

            /*
             * Every account belongs to THIS category's company, derived from the
             * company_id resolved above rather than created independently.
             *
             * This is the single most important line in the file. A bare
             * Account::factory()->asset() creates an account in a company of its own,
             * and a category pointing at five accounts across five other companies is
             * not a fixture - it is a row that TransactionAccountResolver refuses on
             * company grounds, so every test using this factory would fail on a
             * company-isolation error while appearing to test something else entirely.
             *
             * Each state then matches the account's ROLE rather than merely being an
             * account: asset() for the fixed asset itself, contra() for accumulated
             * depreciation (an ASSET with a CREDIT normal balance, which is a
             * requirement and not a preference), expense() for the charge, revenue()
             * for the gain and expense() for the loss.
             */
            'asset_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->asset()
                ->create()
                ->getKey(),

            'accumulated_depreciation_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->asset()
                ->contra()
                ->create()
                ->getKey(),

            'depreciation_expense_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->expense()
                ->create()
                ->getKey(),

            'gain_on_disposal_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->revenue()
                ->create()
                ->getKey(),

            'loss_on_disposal_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->expense()
                ->create()
                ->getKey(),

            'is_active' => true,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /**
     * A category with no gain-on-disposal account, so that a profitable disposal can
     * be tested reaching the "not configured" refusal.
     */
    public function withoutGainAccount(): static
    {
        return $this->state(fn () => ['gain_on_disposal_account_id' => null]);
    }

    /**
     * A category with no loss-on-disposal account.
     */
    public function withoutLossAccount(): static
    {
        return $this->state(fn () => ['loss_on_disposal_account_id' => null]);
    }

    public function life(int $months): static
    {
        return $this->state(fn () => ['useful_life_months' => $months]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
