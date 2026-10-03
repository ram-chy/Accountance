<?php

namespace Tests\Feature\Accounting\FixedAssets;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\FixedAssets\FixedAssetCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Category master data, and the one rule that is about the RESULT rather than the
 * request: a category must have at least one disposal account.
 *
 * The rule is asserted from both directions because the bug it guards against is a
 * partial update that looks harmless: clearing the gain account while the loss account
 * is still set leaves a perfectly usable category, and a validator that read only the
 * payload would refuse it. The mirror case - clearing both - must be refused even
 * though the payload never mentions the accounts at all.
 */
class FixedAssetCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private FixedAssetCategoryService $categories;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categories = app(FixedAssetCategoryService::class);
    }

    /**
     * @return array{company: Company, actor: User, chart: array<string, Account>}
     */
    private function scenario(): array
    {
        $actor = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($actor);

        $chart = [
            'asset' => Account::factory()->for($company)->asset()->create(),
            'accumulated' => Account::factory()->for($company)->asset()->contra()->create(),
            'expense' => Account::factory()->for($company)->expense()->create(),
            'gain' => Account::factory()->for($company)->revenue()->create(),
            'loss' => Account::factory()->for($company)->expense()->create(),
        ];

        return ['company' => $company, 'actor' => $actor, 'chart' => $chart];
    }

    /**
     * @param  array<string, Account>  $chart
     * @return array<string, mixed>
     */
    private function payload(array $chart, array $overrides = []): array
    {
        return array_merge([
            'code' => 'VEH',
            'name' => 'Motor Vehicles',
            'useful_life_months' => 60,
            'asset_account_id' => $chart['asset']->getKey(),
            'accumulated_depreciation_account_id' => $chart['accumulated']->getKey(),
            'depreciation_expense_account_id' => $chart['expense']->getKey(),
            'gain_on_disposal_account_id' => $chart['gain']->getKey(),
            'loss_on_disposal_account_id' => $chart['loss']->getKey(),
        ], $overrides);
    }

    #[Test]
    public function a_category_can_be_created_with_a_gain_account_only(): void
    {
        $s = $this->scenario();

        $category = $this->categories->create($s['company'], $s['actor'], $this->payload($s['chart'], [
            'loss_on_disposal_account_id' => null,
        ]));

        $this->assertNull($category->loss_on_disposal_account_id);
        $this->assertNotNull($category->gain_on_disposal_account_id);
    }

    #[Test]
    public function a_category_cannot_be_created_without_any_disposal_account(): void
    {
        $s = $this->scenario();

        // Neither key is present at all, which is the case the old payload-only check
        // silently allowed.
        $payload = $this->payload($s['chart']);
        unset($payload['gain_on_disposal_account_id'], $payload['loss_on_disposal_account_id']);

        $this->expectException(ValidationException::class);

        $this->categories->create($s['company'], $s['actor'], $payload);
    }

    #[Test]
    public function clearing_the_gain_account_while_the_loss_account_remains_is_allowed(): void
    {
        $s = $this->scenario();

        $category = $this->categories->create($s['company'], $s['actor'], $this->payload($s['chart']));

        $updated = $this->categories->update($category, $s['company'], $s['actor'], [
            'gain_on_disposal_account_id' => null,
        ]);

        $this->assertNull($updated->gain_on_disposal_account_id);
        $this->assertNotNull($updated->loss_on_disposal_account_id, 'The loss account was not in the payload and must survive.');
    }

    #[Test]
    public function clearing_both_disposal_accounts_is_refused(): void
    {
        $s = $this->scenario();

        $category = $this->categories->create($s['company'], $s['actor'], $this->payload($s['chart']));

        $this->expectException(ValidationException::class);

        $this->categories->update($category, $s['company'], $s['actor'], [
            'gain_on_disposal_account_id' => null,
            'loss_on_disposal_account_id' => null,
        ]);
    }

    #[Test]
    public function an_account_belonging_to_another_company_is_refused(): void
    {
        $s = $this->scenario();

        $other = $this->createCompanyFor($this->createUserWithRole(RoleName::Accountant));
        $foreign = Account::factory()->for($other)->expense()->create();

        $this->expectException(ValidationException::class);

        $this->categories->create($s['company'], $s['actor'], $this->payload($s['chart'], [
            'depreciation_expense_account_id' => $foreign->getKey(),
        ]));
    }

    #[Test]
    public function a_category_with_a_gain_and_loss_account_round_trips_through_update(): void
    {
        $s = $this->scenario();

        $category = $this->categories->create($s['company'], $s['actor'], $this->payload($s['chart']));

        // A name-only edit must not disturb the accounts, now that they are merged
        // rather than read from a payload that does not mention them.
        $updated = $this->categories->update($category, $s['company'], $s['actor'], ['name' => 'Vehicles']);

        $this->assertSame('Vehicles', $updated->name);
        $this->assertSame($category->gain_on_disposal_account_id, $updated->gain_on_disposal_account_id);
        $this->assertSame($category->loss_on_disposal_account_id, $updated->loss_on_disposal_account_id);
        $this->assertSame($category->asset_account_id, $updated->asset_account_id);
    }
}
