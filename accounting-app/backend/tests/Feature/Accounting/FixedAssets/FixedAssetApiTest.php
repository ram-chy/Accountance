<?php

namespace Tests\Feature\Accounting\FixedAssets;

use App\Enums\FixedAssetAcquisitionMethod;
use App\Enums\FixedAssetStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fixed asset HTTP surface.
 *
 * Where FixedAssetLifecycleTest asserts the bookkeeping through the services, this
 * file asserts the things only an endpoint can get wrong: that the routes exist and
 * are ordered so `register` is not read as an id, that the acquisition method comes
 * from the route rather than the payload, that another company's asset 404s rather
 * than leaking, and that the permissions actually guard the writes.
 *
 * The fixtures are the same company, chart and category the lifecycle test builds, so
 * a failure here points at the transport rather than at the accounting.
 */
class FixedAssetApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{actor: User, company: Company, chart: array<string, Account>, category: FixedAssetCategory}
     */
    private function scenario(): array
    {
        $actor = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($actor);

        $chart = [
            'cash' => Account::factory()->for($company)->cash()->create(['code' => '1010', 'name' => 'Cash']),
            'bank' => Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Bank']),
            'payable' => Account::factory()->for($company)->liability()->create(['code' => '2010', 'name' => 'Trade Payable']),
            'fixed_asset' => Account::factory()->for($company)->asset()->create(['code' => '1500', 'name' => 'Motor Vehicles']),
            'accumulated' => Account::factory()->for($company)->asset()->contra()->create(['code' => '1510', 'name' => 'Accumulated Depreciation']),
            'depreciation' => Account::factory()->for($company)->expense()->create(['code' => '6100', 'name' => 'Depreciation Expense']),
            'gain' => Account::factory()->for($company)->revenue()->create(['code' => '4900', 'name' => 'Gain on Disposal']),
            'loss' => Account::factory()->for($company)->expense()->create(['code' => '6900', 'name' => 'Loss on Disposal']),
        ];

        $category = FixedAssetCategory::factory()->for($company)->create([
            'code' => 'VEH',
            'name' => 'Motor Vehicles',
            'useful_life_months' => 60,
            'asset_account_id' => $chart['fixed_asset']->getKey(),
            'accumulated_depreciation_account_id' => $chart['accumulated']->getKey(),
            'depreciation_expense_account_id' => $chart['depreciation']->getKey(),
            'gain_on_disposal_account_id' => $chart['gain']->getKey(),
            'loss_on_disposal_account_id' => $chart['loss']->getKey(),
        ]);

        return ['actor' => $actor, 'company' => $company, 'chart' => $chart, 'category' => $category];
    }

    /**
     * The body of a cash purchase, with the money side defaulting to the bank.
     *
     * @param  array{company: Company, chart: array<string, Account>, category: FixedAssetCategory}  $s
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $s, array $overrides = []): array
    {
        return array_merge([
            'fixed_asset_category_id' => $s['category']->getKey(),
            'name' => 'Delivery Van',
            'acquisition_date' => '2027-01-15',
            'depreciation_start_date' => '2027-01-15',
            'original_cost' => '12000.00',
            'salvage_value' => '0',
            'acquisition_account_id' => $s['chart']['bank']->getKey(),
        ], $overrides);
    }

    /**
     * Create a draft asset through the API and return the model.
     *
     * @param  array{actor: User, company: Company, chart: array<string, Account>, category: FixedAssetCategory}  $s
     * @param  array<string, mixed>  $overrides
     */
    private function createDraft(array $s, array $overrides = [], string $route = 'cash'): FixedAsset
    {
        $response = $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$route, $this->payload($s, $overrides));

        $response->assertCreated();

        return FixedAsset::findOrFail($response->json('data.id'));
    }

    #[Test]
    public function an_accountant_can_create_a_cash_asset_draft(): void
    {
        $s = $this->scenario();

        $response = $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/cash', $this->payload($s));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', FixedAssetStatus::Draft->value)
            ->assertJsonPath('data.acquisition_method', FixedAssetAcquisitionMethod::Cash->value)
            ->assertJsonPath('data.original_cost', '12000.0000')
            ->assertJsonPath('data.category.id', $s['category']->getKey());

        $this->assertNotNull($response->json('data.asset_number'));
    }

    #[Test]
    public function a_cash_asset_refuses_a_payable_acquisition_account(): void
    {
        $s = $this->scenario();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/cash', $this->payload($s, [
                'acquisition_account_id' => $s['chart']['payable']->getKey(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function the_supplier_credit_route_credits_a_payable_and_does_not_touch_the_bank(): void
    {
        $s = $this->scenario();

        $asset = $this->createDraft($s, ['acquisition_account_id' => $s['chart']['payable']->getKey()], 'supplier-credit');

        $this->assertSame(FixedAssetAcquisitionMethod::SupplierCredit, $asset->acquisition_method);
        $this->assertSame($s['chart']['payable']->getKey(), $asset->acquisition_account_id);
    }

    #[Test]
    public function a_staff_member_cannot_create_an_asset(): void
    {
        $s = $this->scenario();

        $staff = $this->createUserWithRole(RoleName::Staff);
        $this->addMemberTo($s['company'], $staff);

        $this->actingAsJwt($staff)
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/cash', $this->payload($s))
            ->assertForbidden();
    }

    #[Test]
    public function another_companys_asset_is_not_found(): void
    {
        $s = $this->scenario();
        $asset = $this->createDraft($s);

        $otherActor = $this->createUserWithRole(RoleName::Accountant);
        $otherCompany = $this->createCompanyFor($otherActor);

        $this->actingAsJwt($otherActor)
            ->withCompanyContext($otherCompany)
            ->getJson('/api/accounting/fixed-assets/'.$asset->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function capitalising_through_the_api_posts_the_entry_and_activates_the_asset(): void
    {
        $s = $this->scenario();
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/capitalize')
            ->assertSuccessful()
            ->assertJsonPath('data.status', FixedAssetStatus::Active->value);

        $this->assertNotNull($asset->refresh()->journal_id);
    }

    #[Test]
    public function a_capitalised_asset_cannot_be_updated_or_deleted_over_http(): void
    {
        $s = $this->scenario();
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/capitalize')
            ->assertSuccessful();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->putJson('/api/accounting/fixed-assets/'.$asset->getKey(), ['name' => 'Renamed'])
            ->assertStatus(409);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->deleteJson('/api/accounting/fixed-assets/'.$asset->getKey())
            ->assertStatus(409);
    }

    #[Test]
    public function a_draft_asset_can_be_updated_and_deleted_over_http(): void
    {
        $s = $this->scenario();
        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->putJson('/api/accounting/fixed-assets/'.$asset->getKey(), ['name' => 'Renamed Van'])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Renamed Van');

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->deleteJson('/api/accounting/fixed-assets/'.$asset->getKey())
            ->assertSuccessful();

        $this->assertDatabaseMissing('fixed_assets', ['id' => $asset->getKey()]);
    }

    #[Test]
    public function the_register_lists_active_assets_with_their_derived_values(): void
    {
        $s = $this->scenario();
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/capitalize')
            ->assertSuccessful();

        $response = $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->getJson('/api/accounting/fixed-assets/register');

        $response->assertSuccessful()
            ->assertJsonPath('data.0.asset_number', $asset->refresh()->asset_number)
            ->assertJsonPath('data.0.carrying_value', '12000.0000')
            ->assertJsonPath('data.0.accumulated_depreciation', '0.0000')
            ->assertJsonPath('data.0.remaining_period_count', 60);
    }

    #[Test]
    public function a_draft_asset_is_absent_from_the_register(): void
    {
        $s = $this->scenario();
        $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->getJson('/api/accounting/fixed-assets/register')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_depreciation_schedule_returns_the_upcoming_periods(): void
    {
        $s = $this->scenario();
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/capitalize')
            ->assertSuccessful();

        $response = $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->getJson('/api/accounting/fixed-assets/'.$asset->getKey().'/depreciation-schedule');

        $response->assertSuccessful()
            ->assertJsonCount(60, 'data.upcoming_periods')
            ->assertJsonPath('data.upcoming_periods.0.period_number', 1)
            ->assertJsonPath('data.upcoming_periods.0.amount', '200.0000')
            ->assertJsonPath('data.upcoming_periods.0.period_end_date', '2027-02-14');
    }

    #[Test]
    public function posting_depreciation_through_the_api_charges_the_first_period(): void
    {
        $s = $this->scenario();
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/capitalize')
            ->assertSuccessful();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/depreciation', ['as_of' => '2027-02-14'])
            ->assertSuccessful()
            ->assertJsonPath('data.depreciation.period_number', 1)
            ->assertJsonPath('data.depreciation.amount', '200.0000')
            ->assertJsonPath('data.fixed_asset.accumulated_depreciation', '200.0000');
    }

    #[Test]
    public function disposal_through_the_api_books_the_result_and_removes_the_asset_from_the_register(): void
    {
        $s = $this->scenario();
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/capitalize')
            ->assertSuccessful();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/dispose', [
                'disposal_date' => '2027-02-14',
                'proceeds' => '11000.00',
                'proceeds_account_id' => $s['chart']['bank']->getKey(),
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.disposal.carrying_value_at_disposal', '12000.0000')
            ->assertJsonPath('data.disposal.loss', '1000.0000')
            ->assertJsonPath('data.fixed_asset.status', FixedAssetStatus::Disposed->value);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->getJson('/api/accounting/fixed-assets/register')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_depreciation_report_groups_charges_by_asset(): void
    {
        $s = $this->scenario();
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/capitalize')
            ->assertSuccessful();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/depreciation', ['as_of' => '2027-02-14'])
            ->assertSuccessful();

        $response = $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->getJson('/api/accounting/fixed-assets/depreciation-report?from=2027-01-01&to=2027-12-31');

        $response->assertSuccessful()
            ->assertJsonPath('data.total_depreciation', '200.0000')
            ->assertJsonPath('data.assets.0.asset_number', $asset->refresh()->asset_number)
            ->assertJsonPath('data.assets.0.total_depreciation', '200.0000');
    }

    #[Test]
    public function a_backdated_disposal_is_refused_over_http(): void
    {
        $s = $this->scenario();
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->createDraft($s);

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/capitalize')
            ->assertSuccessful();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/depreciation', ['as_of' => '2027-02-14'])
            ->assertSuccessful();

        $this->actingAsJwt($s['actor'])
            ->withCompanyContext($s['company'])
            ->postJson('/api/accounting/fixed-assets/'.$asset->getKey().'/dispose', [
                'disposal_date' => '2027-01-20',
                'proceeds' => '11000.00',
                'proceeds_account_id' => $s['chart']['bank']->getKey(),
            ])
            ->assertStatus(422);
    }
}
