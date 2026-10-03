<?php

namespace Tests\Feature\Accounting\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Services\Accounting\FixedAssets\FixedAssetService;
use App\Services\Accounting\TransactionAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fixed asset factories must produce rows the application would accept.
 *
 * A factory whose output fails validation is worse than no factory: tests using it
 * fail on an isolation error while appearing to test something else, and the failure
 * gets "fixed" by weakening the assertion rather than the fixture. These tests make
 * the factories' central promise explicit - every account on a factory-made category
 * or asset belongs to that row's company and has the type and normal balance its role
 * requires - by running the resolver over the result rather than trusting the states.
 */
class FixedAssetFactoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_factory_category_has_all_five_accounts_in_its_own_company(): void
    {
        $category = FixedAssetCategory::factory()->create();

        $resolver = app(TransactionAccountResolver::class);

        // Throws if any account is the wrong company, wrong type, or wrong normal
        // balance - which is exactly what a bare Account::factory() would produce.
        $resolver->fixedAsset($category->company, $category->asset_account_id);
        $resolver->accumulatedDepreciation($category->company, $category->accumulated_depreciation_account_id);
        $resolver->depreciationExpense($category->company, $category->depreciation_expense_account_id);
        $resolver->gainOnDisposal($category->company, $category->gain_on_disposal_account_id);
        $resolver->lossOnDisposal($category->company, $category->loss_on_disposal_account_id);

        $this->assertSame($category->company_id, $category->assetAccount->company_id);
        $this->assertSame($category->company_id, $category->accumulatedDepreciationAccount->company_id);
    }

    #[Test]
    public function a_factory_asset_is_a_valid_draft_that_the_service_can_capitalise(): void
    {
        $asset = FixedAsset::factory()->create();

        $this->assertSame('DRAFT', $asset->status->value);
        $this->assertSame($asset->fixed_asset_category_id, $asset->category->getKey());
        $this->assertSame($asset->company_id, $asset->category->company_id);
        $this->assertNotNull($asset->created_by);
    }

    #[Test]
    public function a_factory_asset_can_be_capitalised_by_the_real_service(): void
    {
        $asset = FixedAsset::factory()->create();

        $this->makePeriodFor($asset->company, now()->toDateString(), 'Current');

        $capitalised = app(FixedAssetService::class)->capitalise($asset, $asset->creator);

        $this->assertSame('ACTIVE', $capitalised->status->value);
        $this->assertNotNull($capitalised->journal_id);
    }

    #[Test]
    public function for_category_anchors_the_asset_to_that_category_and_company(): void
    {
        $category = FixedAssetCategory::factory()->create();

        $asset = FixedAsset::factory()->forCategory($category)->create();

        $this->assertSame($category->getKey(), $asset->fixed_asset_category_id);
        $this->assertSame($category->company_id, $asset->company_id);
        $this->assertSame($category->asset_account_id, $asset->asset_account_id);
        $this->assertSame($category->useful_life_months, $asset->useful_life_months);
    }
}
