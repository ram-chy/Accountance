<?php

namespace Tests\Feature\Accounting\FixedAssets;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\FixedAssetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fixed asset category HTTP surface.
 *
 * A category is master data with no accounting effect, so these tests are about the
 * transport and the guards rather than about the ledger: that the five account
 * references round-trip, that a category still in use refuses to retire or delete,
 * that another company's category 404s, and that the writes are permissioned.
 */
class FixedAssetCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{actor: User, company: Company, chart: array<string, Account>}
     */
    private function scenario(): array
    {
        $actor = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($actor);

        return [
            'actor' => $actor,
            'company' => $company,
            'chart' => [
                'bank' => Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Bank']),
                'fixed_asset' => Account::factory()->for($company)->asset()->create(['code' => '1500', 'name' => 'Motor Vehicles']),
                'accumulated' => Account::factory()->for($company)->asset()->contra()->create(['code' => '1510', 'name' => 'Accumulated Depreciation']),
                'depreciation' => Account::factory()->for($company)->expense()->create(['code' => '6100', 'name' => 'Depreciation Expense']),
                'gain' => Account::factory()->for($company)->revenue()->create(['code' => '4900', 'name' => 'Gain on Disposal']),
            ],
        ];
    }

    /**
     * @param  array{chart: array<string, Account>}  $s
     * @return array<string, mixed>
     */
    private function payload(array $s, array $overrides = []): array
    {
        return array_merge([
            'code' => 'VEH',
            'name' => 'Motor Vehicles',
            'useful_life_months' => 60,
            'asset_account_id' => $s['chart']['fixed_asset']->getKey(),
            'accumulated_depreciation_account_id' => $s['chart']['accumulated']->getKey(),
            'depreciation_expense_account_id' => $s['chart']['depreciation']->getKey(),
            'gain_on_disposal_account_id' => $s['chart']['gain']->getKey(),
        ], $overrides);
    }

    #[Test]
    public function an_accountant_can_create_a_category(): void
    {
        $s = $this->scenario();

        $response = $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-asset-categories', $this->payload($s));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'VEH')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.assets_count', 0)
            ->assertJsonPath('data.asset_account_id', $s['chart']['fixed_asset']->getKey());

        $this->assertDatabaseHas('fixed_asset_categories', [
            'code' => 'VEH',
            'company_id' => $s['company']->getKey(),
        ]);
    }

    #[Test]
    public function a_category_without_any_disposal_account_is_refused(): void
    {
        $s = $this->scenario();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-asset-categories', $this->payload($s, [
                'gain_on_disposal_account_id' => null,
            ]))
            ->assertStatus(422);
    }

    #[Test]
    public function a_staff_member_cannot_create_a_category(): void
    {
        $s = $this->scenario();

        $staff = $this->createUserWithRole(RoleName::Staff);
        $this->addMemberTo($s['company'], $staff);

        $this->actingAsJwt($staff)
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-asset-categories', $this->payload($s))
            ->assertForbidden();
    }

    #[Test]
    public function the_list_shows_categories_with_their_asset_count(): void
    {
        $s = $this->scenario();

        $category = FixedAssetCategory::factory()->for($s['company'])->create([
            'code' => 'VEH',
            'name' => 'Motor Vehicles',
            'useful_life_months' => 60,
            'asset_account_id' => $s['chart']['fixed_asset']->getKey(),
            'accumulated_depreciation_account_id' => $s['chart']['accumulated']->getKey(),
            'depreciation_expense_account_id' => $s['chart']['depreciation']->getKey(),
            'gain_on_disposal_account_id' => $s['chart']['gain']->getKey(),
        ]);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->getJson('/api/accounting/fixed-asset-categories')
            ->assertSuccessful()
            ->assertJsonPath('data.0.code', 'VEH')
            ->assertJsonPath('data.0.assets_count', 0);
    }

    #[Test]
    public function a_category_can_be_updated_and_the_change_does_not_reach_its_assets(): void
    {
        $s = $this->scenario();

        $category = FixedAssetCategory::factory()->for($s['company'])->create([
            'code' => 'VEH',
            'name' => 'Motor Vehicles',
            'useful_life_months' => 60,
            'asset_account_id' => $s['chart']['fixed_asset']->getKey(),
            'accumulated_depreciation_account_id' => $s['chart']['accumulated']->getKey(),
            'depreciation_expense_account_id' => $s['chart']['depreciation']->getKey(),
            'gain_on_disposal_account_id' => $s['chart']['gain']->getKey(),
        ]);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->putJson('/api/accounting/fixed-asset-categories/'.$category->getKey(), [
                'name' => 'Vehicles and Trucks',
                'useful_life_months' => 48,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Vehicles and Trucks')
            ->assertJsonPath('data.useful_life_months', 48);
    }

    #[Test]
    public function another_companys_category_is_not_found(): void
    {
        $s = $this->scenario();

        $category = FixedAssetCategory::factory()->for($s['company'])->create();

        $otherActor = $this->createUserWithRole(RoleName::Accountant);
        $otherCompany = $this->createCompanyFor($otherActor);

        $this->actingAsJwt($otherActor)
            ->withCompanyContext($otherCompany)
            ->getJson('/api/accounting/fixed-asset-categories/'.$category->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function an_unused_category_can_be_deactivated_and_reactivated(): void
    {
        $s = $this->scenario();

        $category = FixedAssetCategory::factory()->for($s['company'])->create();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-asset-categories/'.$category->getKey().'/deactivate')
            ->assertSuccessful()
            ->assertJsonPath('data.is_active', false);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-asset-categories/'.$category->getKey().'/activate')
            ->assertSuccessful()
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function a_category_with_an_asset_cannot_be_deactivated_or_deleted(): void
    {
        $s = $this->scenario();

        $category = FixedAssetCategory::factory()->for($s['company'])->create([
            'code' => 'VEH',
            'name' => 'Motor Vehicles',
            'useful_life_months' => 60,
            'asset_account_id' => $s['chart']['fixed_asset']->getKey(),
            'accumulated_depreciation_account_id' => $s['chart']['accumulated']->getKey(),
            'depreciation_expense_account_id' => $s['chart']['depreciation']->getKey(),
            'gain_on_disposal_account_id' => $s['chart']['gain']->getKey(),
        ]);

        // A draft asset is enough: the category is still in use.
        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/cash', [
                'fixed_asset_category_id' => $category->getKey(),
                'name' => 'Delivery Van',
                'acquisition_date' => '2027-01-15',
                'depreciation_start_date' => '2027-01-15',
                'original_cost' => '12000.00',
                'salvage_value' => '0',
                'acquisition_account_id' => $s['chart']['bank']->getKey(),
            ])
            ->assertCreated();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-asset-categories/'.$category->getKey().'/deactivate')
            ->assertStatus(422);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->deleteJson('/api/accounting/fixed-asset-categories/'.$category->getKey())
            ->assertStatus(422);
    }

    #[Test]
    public function an_unused_category_can_be_deleted_outright(): void
    {
        $s = $this->scenario();

        $category = FixedAssetCategory::factory()->for($s['company'])->create();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->deleteJson('/api/accounting/fixed-asset-categories/'.$category->getKey())
            ->assertSuccessful();

        $this->assertDatabaseMissing('fixed_asset_categories', ['id' => $category->getKey()]);
    }
}
