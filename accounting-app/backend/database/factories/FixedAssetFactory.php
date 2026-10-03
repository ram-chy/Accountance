<?php

namespace Database\Factories;

use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetAcquisitionMethod;
use App\Enums\FixedAssetStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<FixedAsset>
 */
class FixedAssetFactory extends Factory
{
    protected $model = FixedAsset::class;

    /**
     * A DRAFT asset, which is the only state this factory produces without being
     * asked otherwise.
     *
     * A factory that rolled the status could produce a capitalised asset with no
     * journal_id - which the schema's CHECK constraints would reject, and rightly: a
     * capitalised asset is a claim about the ledger, and a factory should not be able
     * to make that claim without a journal behind it. Tests that need a capitalised
     * asset call FixedAssetService::capitalise(), which is also what makes them
     * meaningful.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * fixed_asset_category_id first and company_id derived from it, following
             * the SalesInvoiceFactory convention: Eloquent resolves factory
             * attributes in declaration order, so the category can be the anchor
             * everything else is made consistent with.
             *
             * Without that, the category, the acquisition account and the company
             * would each be created by their own factory and would belong to three
             * different companies - and every service call would then fail on company
             * isolation with an error that says nothing about what the test meant to
             * check.
             *
             * The five category-sourced account columns are copied from the category
             * further down rather than left blank - see the note there for why they
             * cannot simply be omitted.
             */
            'fixed_asset_category_id' => FixedAssetCategory::factory(),
            'company_id' => fn (array $attributes) => FixedAssetCategory::findOrFail(
                $attributes['fixed_asset_category_id']
            )->company_id,

            'asset_number' => 'FA-'.fake()->unique()->numerify('######'),
            'name' => fake()->unique()->words(3, true),
            'description' => null,
            'serial_number' => null,
            'supplier_reference' => null,
            'acquisition_date' => Carbon::today()->startOfMonth()->toDateString(),
            /*
             * The five category-sourced account columns are copied from the category
             * here, rather than left out.
             *
             * An earlier version of this file deliberately omitted them, on the
             * reasonable-sounding grounds that they belong to the service. That was
             * wrong: three of them are NOT NULL, so the factory could not produce a
             * valid row at all and every test using it failed on an integrity error
             * before reaching the behaviour it meant to check.
             *
             * Copying them from the category is the same value
             * FixedAssetService::create() writes, so nothing is bypassed - and the
             * claim the factory must not be able to make on its own is still the
             * important one, which is that a capitalised ASSET needs a journal behind
             * it. That is what the schema's CHECK constraints enforce, and it is why
             * this factory only ever produces a DRAFT.
             */
            'useful_life_months' => fn (array $attributes) => FixedAssetCategory::findOrFail(
                $attributes['fixed_asset_category_id']
            )->useful_life_months,
            'asset_account_id' => fn (array $attributes) => FixedAssetCategory::findOrFail(
                $attributes['fixed_asset_category_id']
            )->asset_account_id,
            'accumulated_depreciation_account_id' => fn (array $attributes) => FixedAssetCategory::findOrFail(
                $attributes['fixed_asset_category_id']
            )->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => fn (array $attributes) => FixedAssetCategory::findOrFail(
                $attributes['fixed_asset_category_id']
            )->depreciation_expense_account_id,
            'gain_on_disposal_account_id' => fn (array $attributes) => FixedAssetCategory::findOrFail(
                $attributes['fixed_asset_category_id']
            )->gain_on_disposal_account_id,
            'loss_on_disposal_account_id' => fn (array $attributes) => FixedAssetCategory::findOrFail(
                $attributes['fixed_asset_category_id']
            )->loss_on_disposal_account_id,

            'original_cost' => '12000.0000',
            'salvage_value' => '0.0000',
            'depreciation_method' => DepreciationMethod::StraightLine,
            'depreciation_start_date' => Carbon::today()->startOfMonth()->toDateString(),
            'status' => FixedAssetStatus::Draft,
            'acquisition_method' => FixedAssetAcquisitionMethod::Cash,

            /*
             * Resolved against the company the category belongs to, so it is a real
             * account of THIS company - and marked cash, because a CASH acquisition
             * whose account is not classified as cash or bank is refused by
             * TransactionAccountResolver::acquisitionAccount() before any journal
             * exists.
             */
            'acquisition_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail(
                    FixedAssetCategory::findOrFail($attributes['fixed_asset_category_id'])->company_id
                ))
                ->cash()
                ->create()
                ->getKey(),

            'journal_id' => null,
            'capitalised_at' => null,
            'capitalised_by' => null,

            /*
             * NOT NULL on the table, so a real user rather than null. Users are not
             * company-scoped - membership is a separate relation - so there is nothing
             * to make consistent with the asset's company here.
             */
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    /**
     * A cash purchase.
     *
     * The default, and a state anyway so that a test asserting the SUPPLIER_CREDIT
     * entry can say which one it is rather than relying on having read the factory.
     */
    public function cashPurchase(): static
    {
        return $this->state(fn () => [
            'acquisition_method' => FixedAssetAcquisitionMethod::Cash,
        ]);
    }

    /**
     * A purchase on supplier credit, which credits a payable rather than a bank.
     *
     * Replaces the acquisition account as well as the method, because the two must
     * agree: a SUPPLIER_CREDIT asset pointing at a cash account is refused by the
     * resolver, and a state that produced that would fail for a reason of its own
     * making.
     */
    public function supplierCredit(): static
    {
        return $this->state(fn (array $attributes) => [
            'acquisition_method' => FixedAssetAcquisitionMethod::SupplierCredit,
            'acquisition_account_id' => Account::factory()->liability()->create([
                'company_id' => FixedAssetCategory::findOrFail(
                    $attributes['fixed_asset_category_id']
                )->company_id,
            ])->getKey(),
        ]);
    }

    public function cost(string|float|int $cost): static
    {
        return $this->state(fn () => ['original_cost' => number_format((float) $cost, 4, '.', '')]);
    }

    public function salvage(string|float|int $salvage): static
    {
        return $this->state(fn () => ['salvage_value' => number_format((float) $salvage, 4, '.', '')]);
    }

    public function life(int $months): static
    {
        return $this->state(fn () => ['useful_life_months' => $months]);
    }

    /**
     * Depreciation begins on a specific date, which is what determines the schedule's
     * period boundaries.
     */
    public function startingOn(Carbon $date): static
    {
        return $this->state(fn () => [
            'depreciation_start_date' => $date->toDateString(),
            'acquisition_date' => $date->toDateString(),
        ]);
    }

    /**
     * A specific category, which supplies the five account columns and the useful
     * life as well as the company.
     */
    public function forCategory(FixedAssetCategory $category): static
    {
        return $this->state(fn () => [
            'company_id' => $category->company_id,
            'fixed_asset_category_id' => $category->getKey(),
            'useful_life_months' => $category->useful_life_months,
            'asset_account_id' => $category->asset_account_id,
            'accumulated_depreciation_account_id' => $category->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => $category->depreciation_expense_account_id,
            'gain_on_disposal_account_id' => $category->gain_on_disposal_account_id,
            'loss_on_disposal_account_id' => $category->loss_on_disposal_account_id,
        ]);
    }
}
