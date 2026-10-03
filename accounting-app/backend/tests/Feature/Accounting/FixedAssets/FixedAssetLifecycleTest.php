<?php

namespace Tests\Feature\Accounting\FixedAssets;

use App\Enums\FixedAssetAcquisitionMethod;
use App\Enums\FixedAssetStatus;
use App\Enums\JournalSource;
use App\Enums\JournalStatus;
use App\Enums\RoleName;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetDepreciation;
use App\Models\User;
use App\Services\Accounting\FixedAssets\FixedAssetDepreciationCalculator;
use App\Services\Accounting\FixedAssets\FixedAssetDepreciationService;
use App\Services\Accounting\FixedAssets\FixedAssetDisposalService;
use App\Services\Accounting\FixedAssets\FixedAssetService;
use App\Services\Accounting\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fixed asset lifecycle, through the services.
 *
 * These tests are about the ACCOUNTING, and they assert it at the journal rather
 * than through the API: every claim here is "which accounts were debited and
 * credited, by how much, and did the register agree afterwards". The HTTP surface
 * has its own tests, and a test that went through routes would be unable to say
 * whether a failure was a routing problem or a bookkeeping one.
 *
 * The three claims:
 *
 * 1. Capitalisation produces Dr Fixed Asset / Cr Cash or Payable, and moves the
 *    asset from DRAFT to ACTIVE with its category's accounts copied onto it.
 * 2. Depreciation charges the earliest unposted period only, and the periods sum
 *    EXACTLY to the depreciable base - not approximately, and not leaving a
 *    fraction behind after the final period.
 * 3. Disposal balances, with the accumulated depreciation leg debited for the
 *    TOTAL written down rather than for the carrying value, and produces a gain or
 *    a loss measured against book value.
 *
 * Claim 3's parenthetical is not pedantry: the two figures differ by the whole
 * accumulated amount, and getting it wrong produces an entry that does not balance.
 */
class FixedAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FixedAssetService $assets;

    private FixedAssetDepreciationService $depreciation;

    private FixedAssetDisposalService $disposal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = app(FixedAssetService::class);
        $this->depreciation = app(FixedAssetDepreciationService::class);
        $this->disposal = app(FixedAssetDisposalService::class);
    }

    /**
     * A company, an accountant, and a chart of accounts with everything this phase
     * needs: cash and bank for the money side, a payable for supplier credit, an
     * asset and its contra, a depreciation expense, and gain and loss accounts.
     *
     * @return array{company: Company, actor: User, chart: array<string, Account>, category: FixedAssetCategory}
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

        return ['company' => $company, 'actor' => $actor, 'chart' => $chart, 'category' => $category];
    }

    /**
     * Register a draft asset.
     *
     * @param  array<string, Account>  $chart
     * @param  array<string, mixed>  $overrides
     */
    private function draft(
        Company $company,
        User $actor,
        $category,
        array $chart,
        FixedAssetAcquisitionMethod $method,
        array $overrides = []
    ): FixedAsset {
        return $this->assets->create($company, $actor, $method, array_merge([
            'fixed_asset_category_id' => $category->getKey(),
            'name' => 'Delivery Van',
            'acquisition_date' => '2027-01-15',
            'depreciation_start_date' => '2027-01-15',
            'original_cost' => '12000.0000',
            'salvage_value' => '0.0000',
            'acquisition_account_id' => $method === FixedAssetAcquisitionMethod::Cash
                ? $chart['bank']->getKey()
                : $chart['payable']->getKey(),
        ], $overrides));
    }

    #[Test]
    public function capitalising_a_cash_purchase_debits_the_asset_and_credits_the_bank(): void
    {
        $s = $this->scenario();

        // The capitalisation entry posts into January 2027.
        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->draft(
            $s['company'],
            $s['actor'],
            $s['category'],
            $s['chart'],
            FixedAssetAcquisitionMethod::Cash
        );

        $this->assertSame(FixedAssetStatus::Draft, $asset->status);
        $this->assertNull($asset->journal_id, 'A draft has no journal.');

        $capitalised = $this->assets->capitalise($asset, $s['actor']);

        $this->assertSame(FixedAssetStatus::Active, $capitalised->status);
        $this->assertNotNull($capitalised->journal_id);
        $this->assertNotNull($capitalised->capitalised_at);

        // The category's accounts were copied onto the asset, not joined.
        $this->assertSame($s['chart']['fixed_asset']->getKey(), $capitalised->asset_account_id);
        $this->assertSame($s['chart']['accumulated']->getKey(), $capitalised->accumulated_depreciation_account_id);
        $this->assertSame(60, (int) $capitalised->useful_life_months);

        $journal = $capitalised->journal;
        $this->assertSame(JournalStatus::Posted, $journal->status);
        $this->assertSame(JournalSource::FixedAsset, $journal->source_type);
        $this->assertSame($capitalised->getKey(), (int) $journal->source_id);

        $lines = $journal->lines()->orderBy('line_number')->get();

        $this->assertCount(2, $lines);

        $this->assertSame($s['chart']['fixed_asset']->getKey(), $lines[0]->account_id);
        $this->assertSame('12000.0000', $lines[0]->debit);
        $this->assertSame('0.0000', $lines[0]->credit);

        $this->assertSame($s['chart']['bank']->getKey(), $lines[1]->account_id);
        $this->assertSame('0.0000', $lines[1]->debit);
        $this->assertSame('12000.0000', $lines[1]->credit);
    }

    #[Test]
    public function capitalising_on_supplier_credit_credits_a_payable_instead_of_a_bank(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->draft(
            $s['company'],
            $s['actor'],
            $s['category'],
            $s['chart'],
            FixedAssetAcquisitionMethod::SupplierCredit
        );

        $capitalised = $this->assets->capitalise($asset, $s['actor']);

        $lines = $capitalised->journal->lines()->orderBy('line_number')->get();

        $this->assertCount(2, $lines);
        $this->assertSame($s['chart']['payable']->getKey(), $lines[1]->account_id);
        $this->assertSame('12000.0000', $lines[1]->credit);

        // A credit purchase raises a payable and does NOT reduce the bank.
        $this->assertTrue(
            app(LedgerService::class)
                ->balanceFor($s['chart']['bank']->fresh())
                ->equals(Money::zero()),
            'A supplier-credit acquisition must not touch the bank.'
        );
    }

    #[Test]
    public function a_cash_acquisition_refuses_a_payable_account(): void
    {
        $s = $this->scenario();

        $this->expectException(ValidationException::class);

        $this->draft(
            $s['company'],
            $s['actor'],
            $s['category'],
            $s['chart'],
            FixedAssetAcquisitionMethod::Cash,
            ['acquisition_account_id' => $s['chart']['payable']->getKey()]
        );
    }

    #[Test]
    public function capitalising_twice_is_refused(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        $this->expectException(ConflictException::class);

        $this->assets->capitalise($asset->refresh(), $s['actor']);
    }

    #[Test]
    public function a_cash_purchase_is_refused_when_the_account_is_not_cash_or_bank(): void
    {
        $s = $this->scenario();

        // An ordinary asset account is not classified as cash or bank.
        $this->expectException(ValidationException::class);

        $this->draft(
            $s['company'],
            $s['actor'],
            $s['category'],
            $s['chart'],
            FixedAssetAcquisitionMethod::Cash,
            ['acquisition_account_id' => $s['chart']['fixed_asset']->getKey()]
        );
    }

    #[Test]
    public function depreciating_charges_expense_and_credits_accumulated_depreciation(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        // 12000 over 60 months is exactly 200.00 a month.
        $row = $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-02-14'));

        $this->assertSame(1, $row->period_number);
        $this->assertSame('200.0000', $row->amount);
        $this->assertSame('2027-01-15', $row->period_start_date->toDateString());
        $this->assertSame('2027-02-14', $row->period_end_date->toDateString());

        $lines = $row->journal->lines()->orderBy('line_number')->get();

        $this->assertCount(2, $lines);
        $this->assertSame($s['chart']['depreciation']->getKey(), $lines[0]->account_id);
        $this->assertSame('200.0000', $lines[0]->debit);
        $this->assertSame($s['chart']['accumulated']->getKey(), $lines[1]->account_id);
        $this->assertSame('200.0000', $lines[1]->credit);

        $this->assertSame(JournalSource::FixedAssetDepreciation, $row->journal->source_type);

        /*
         * source_id is the DEPRECIATION ROW, not the asset - the enum's contract is
         * that it resolves against the table source_type names. It also had to be set
         * after the row was inserted, which is why the journal is created as a draft
         * and posted only once the row exists.
         */
        $this->assertSame($row->getKey(), (int) $row->journal->source_id);
    }

    #[Test]
    public function a_period_cannot_be_charged_before_it_has_ended(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        $this->expectException(ValidationException::class);

        // Period 1 ends 2027-02-14, so it cannot be charged on 2027-02-01.
        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-02-01'));
    }

    #[Test]
    public function depreciation_runs_in_period_order_and_refuses_to_skip_one(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');
        $this->makePeriodFor($s['company'], '2027-03-14', 'March 2027');
        $this->makePeriodFor($s['company'], '2027-04-14', 'April 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        /*
         * Four periods are open and three have elapsed: period 1 ends 2027-02-14,
         * period 2 ends 2027-03-14, period 3 ends 2027-04-14 and period 4 does not end
         * until 2027-05-14. Each charge posts on its period's END date, which is why
         * the entries land in the month after the charge begins.
         */
        $first = $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-04-14'));
        $second = $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-04-14'));
        $third = $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-04-14'));

        $this->assertSame([1, 2, 3], [$first->period_number, $second->period_number, $third->period_number]);
        $this->assertSame(
            ['2027-02-14', '2027-03-14', '2027-04-14'],
            [
                $first->journal->journal_date->toDateString(),
                $second->journal->journal_date->toDateString(),
                $third->journal->journal_date->toDateString(),
            ],
            'Each charge must post on its own period end.'
        );

        // Period 4 has not ended, so there is nothing left to charge.
        $this->expectException(ValidationException::class);

        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-04-14'));
    }

    #[Test]
    public function the_final_period_absorbs_the_rounding_remainder_so_the_base_is_exhausted_exactly(): void
    {
        $s = $this->scenario();

        // 1000 over 3 months is 333.3333 recurring; the third period must take the
        // odd fraction rather than leaving it behind forever.
        $category = FixedAssetCategory::factory()->for($s['company'])->create([
            'code' => 'TOY',
            'name' => 'Test Equipment',
            'useful_life_months' => 3,
            'asset_account_id' => $s['chart']['fixed_asset']->getKey(),
            'accumulated_depreciation_account_id' => $s['chart']['accumulated']->getKey(),
            'depreciation_expense_account_id' => $s['chart']['depreciation']->getKey(),
            'gain_on_disposal_account_id' => $s['chart']['gain']->getKey(),
            'loss_on_disposal_account_id' => $s['chart']['loss']->getKey(),
        ]);

        $asset = $this->draft($s['company'], $s['actor'], $category, $s['chart'], FixedAssetAcquisitionMethod::Cash, [
            'original_cost' => '1000.0000',
        ]);

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->assets->capitalise($asset, $s['actor']);

        $amounts = [];

        for ($i = 1; $i <= 3; $i++) {
            $asOf = Carbon::parse('2027-01-15')->addMonthsNoOverflow($i)->subDay();

            // A period per month for three months.
            $this->makePeriodFor($s['company'], $asOf->toDateString(), 'Period '.$i);

            $amounts[] = (string) $this->depreciation
                ->depreciate($asset->refresh(), $s['actor'], $asOf)
                ->amount;
        }

        $this->assertSame(['333.3333', '333.3333', '333.3334'], $amounts);

        // The three periods sum to the cost exactly, not to 999.9999.
        $total = Money::zero();
        foreach ($amounts as $amount) {
            $total = $total->plus(Money::of($amount));
        }

        $this->assertTrue(
            $total->equals(Money::of('1000.0000')),
            sprintf('Periods summed to %s rather than 1000.0000.', (string) $total)
        );

        // And the asset is now FULLY_DEPRECIATED, at exactly salvage.
        $fresh = $asset->refresh();

        $this->assertSame(FixedAssetStatus::FullyDepreciated, $fresh->status);
        $this->assertTrue($fresh->carryingAmount()->equals(Money::zero()));
    }

    #[Test]
    public function a_fully_depreciated_asset_cannot_be_depreciated_again(): void
    {
        $s = $this->scenario();

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash, [
            'original_cost' => '1000.0000',
            'salvage_value' => '1000.0000',
        ]);

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->assets->capitalise($asset, $s['actor']);

        $this->expectException(ValidationException::class);

        // Salvage equals cost, so there is nothing at all to depreciate.
        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2030-01-01'));
    }

    #[Test]
    public function disposing_at_a_loss_balances_and_books_a_loss_against_book_value(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');
        $this->makePeriodFor($s['company'], '2027-03-14', 'March 2027');
        $this->makePeriodFor($s['company'], '2027-07-14', 'July 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        // Two months of depreciation at 200.00, so book value is 11,600. The second
        // charge posts on 2027-03-14, which is why March needs a period.
        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-02-14'));
        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-03-14'));

        $disposal = $this->disposal->dispose($asset->refresh(), $s['actor'], [
            'disposal_date' => '2027-07-14',
            'reason' => 'SOLD',
            'proceeds' => '9000.0000',
            'proceeds_account_id' => $s['chart']['bank']->getKey(),
        ]);

        // Carrying value is 12000 - 400 = 11600; sold for 9000, so the loss is 2600.
        $this->assertSame('11600.0000', $disposal->carrying_value_at_disposal);
        $this->assertSame('9000.0000', $disposal->proceeds);
        $this->assertSame('0.0000', $disposal->gain);
        $this->assertSame('2600.0000', $disposal->loss);

        // source_id resolves against fixed_asset_disposals, the table source_type
        // names - not against the asset.
        $this->assertSame($disposal->getKey(), (int) $disposal->journal->source_id);

        $lines = $disposal->journal->lines()->orderBy('line_number')->get();

        // Dr AccDep 400, Dr Loss 2600, Dr Bank 9000, Cr Fixed Asset 12000.
        $this->assertCount(4, $lines);

        $this->assertSame($s['chart']['accumulated']->getKey(), $lines[0]->account_id);
        $this->assertSame('400.0000', $lines[0]->debit, 'The accumulated leg is the TOTAL written down, not the carrying value.');

        $this->assertSame($s['chart']['loss']->getKey(), $lines[1]->account_id);
        $this->assertSame('2600.0000', $lines[1]->debit);

        $this->assertSame($s['chart']['bank']->getKey(), $lines[2]->account_id);
        $this->assertSame('9000.0000', $lines[2]->debit);

        $this->assertSame($s['chart']['fixed_asset']->getKey(), $lines[3]->account_id);
        $this->assertSame('12000.0000', $lines[3]->credit);

        // And the asset is gone from the register.
        $this->assertSame(FixedAssetStatus::Disposed, $asset->refresh()->status);
        $this->assertNotNull($asset->disposed_at);
    }

    #[Test]
    public function disposing_at_a_profit_books_a_gain_and_leaves_no_loss_line(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');
        $this->makePeriodFor($s['company'], '2027-03-14', 'March 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-02-14'));

        // Book value 11800, sold for 13000, so the gain is 1200.
        $disposal = $this->disposal->dispose($asset->refresh(), $s['actor'], [
            'disposal_date' => '2027-03-14',
            'proceeds' => '13000.0000',
            'proceeds_account_id' => $s['chart']['bank']->getKey(),
        ]);

        $this->assertSame('11800.0000', $disposal->carrying_value_at_disposal);
        $this->assertSame('1200.0000', $disposal->gain);
        $this->assertSame('0.0000', $disposal->loss);

        $lines = $disposal->journal->lines()->orderBy('line_number')->get();

        // Dr AccDep 200, Dr Bank 13000, Cr Fixed Asset 12000, Cr Gain 1200.
        $this->assertCount(4, $lines);

        $accountIds = $lines->pluck('account_id')->all();

        $this->assertNotContains(
            $s['chart']['loss']->getKey(),
            $accountIds,
            'A profitable disposal must not touch the loss account at all.'
        );
        $this->assertContains($s['chart']['gain']->getKey(), $accountIds);
    }

    #[Test]
    public function selling_a_fully_depreciated_asset_for_nothing_still_balances(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash, [
            'original_cost' => '600.0000',
        ]);

        /*
         * A one-month life, set directly rather than through a category, because the
         * point of this test is the disposal entry's SHAPE at zero book value and the
         * shortest life that reaches it. The column is server-owned in the
         * application; here it is deliberately bypassed to set up the fixture, which
         * is the same thing a factory state does and is the only legitimate reason to
         * reach past the service.
         */
        $asset->forceFill(['useful_life_months' => 1])->save();

        $this->assets->capitalise($asset, $s['actor']);

        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');
        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-02-14'));

        $this->assertSame(FixedAssetStatus::FullyDepreciated, $asset->refresh()->status);

        $this->makePeriodFor($s['company'], '2027-03-14', 'March 2027');

        // Scrapped. Accumulated is 600, carrying is zero, proceeds are zero.
        $disposal = $this->disposal->dispose($asset->refresh(), $s['actor'], [
            'disposal_date' => '2027-03-14',
            'reason' => 'SCRAPPED',
            'proceeds' => '0.0000',
            'proceeds_account_id' => $s['chart']['bank']->getKey(),
        ]);

        $this->assertSame('0.0000', $disposal->carrying_value_at_disposal);
        $this->assertSame('0.0000', $disposal->gain);
        $this->assertSame('0.0000', $disposal->loss);

        $lines = $disposal->journal->lines()->orderBy('line_number')->get();

        $this->assertCount(2, $lines, 'Dr accumulated depreciation, Cr fixed asset.');

        $this->assertSame($s['chart']['accumulated']->getKey(), $lines[0]->account_id);
        $this->assertSame('600.0000', $lines[0]->debit);

        $this->assertSame($s['chart']['fixed_asset']->getKey(), $lines[1]->account_id);
        $this->assertSame('600.0000', $lines[1]->credit);
    }

    #[Test]
    public function a_disposed_asset_can_neither_be_depreciated_nor_disposed_again(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        $this->disposal->dispose($asset->refresh(), $s['actor'], [
            'disposal_date' => '2027-02-14',
            'proceeds' => '11000.0000',
            'proceeds_account_id' => $s['chart']['bank']->getKey(),
        ]);

        $disposed = $asset->refresh();

        $this->assertSame(FixedAssetStatus::Disposed, $disposed->status);

        // A disposed asset has completed every period it had, so a period-count check
        // would say it is finished rather than disposed. Only the status knows better.
        $this->expectException(ConflictException::class);

        $this->depreciation->depreciate($disposed, $s['actor'], Carbon::parse('2030-01-01'));
    }

    #[Test]
    public function an_asset_cannot_be_disposed_twice(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        $data = [
            'disposal_date' => '2027-02-14',
            'proceeds' => '11000.0000',
            'proceeds_account_id' => $s['chart']['bank']->getKey(),
        ];

        $this->disposal->dispose($asset->refresh(), $s['actor'], $data);

        $this->expectException(ConflictException::class);

        $this->disposal->dispose($asset->refresh(), $s['actor'], $data);
    }

    #[Test]
    public function a_draft_asset_has_no_ledger_effect_at_all(): void
    {
        $s = $this->scenario();

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);

        $this->assertNull($asset->journal_id);
        $this->assertSame(0, FixedAssetDepreciation::query()->count());

        $this->assertTrue(
            app(LedgerService::class)
                ->balanceFor($s['chart']['fixed_asset']->fresh())
                ->equals(Money::zero()),
            'A draft must not appear on the balance sheet.'
        );
    }

    #[Test]
    public function a_capitalised_asset_cannot_be_edited_or_deleted(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        try {
            $this->assets->update($asset->refresh(), $s['company'], $s['actor'], ['original_cost' => '99999.0000']);
            $this->fail('Editing a capitalised asset should have been refused.');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('cannot be edited', $e->getMessage());
        }

        try {
            $this->assets->delete($asset->refresh(), $s['actor']);
            $this->fail('Deleting a capitalised asset should have been refused.');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // And the cost is untouched.
        $this->assertSame('12000.0000', $asset->fresh()->original_cost);
    }

    #[Test]
    public function the_accumulated_depreciation_account_must_be_credit_normal(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');

        // A debit-normal account named like accumulated depreciation. Posting to it
        // would INCREASE the asset on the balance sheet and the trial balance would
        // still foot, so this is the one check worth writing a test for by name.
        $badContra = Account::factory()->for($s['company'])->asset()->create([
            'code' => '1520',
            'name' => 'Accumulated Depreciation (Wrong)',
        ]);

        $category = FixedAssetCategory::factory()->for($s['company'])->create([
            'code' => 'BAD',
            'name' => 'Bad Category',
            'asset_account_id' => $s['chart']['fixed_asset']->getKey(),
            'accumulated_depreciation_account_id' => $badContra->getKey(),
            'depreciation_expense_account_id' => $s['chart']['depreciation']->getKey(),
            'gain_on_disposal_account_id' => $s['chart']['gain']->getKey(),
            'loss_on_disposal_account_id' => $s['chart']['loss']->getKey(),
        ]);

        $this->expectException(ValidationException::class);

        $this->draft($s['company'], $s['actor'], $category, $s['chart'], FixedAssetAcquisitionMethod::Cash);
    }

    #[Test]
    public function the_calculator_reports_a_schedule_that_sums_to_the_depreciable_base(): void
    {
        $s = $this->scenario();

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash, [
            'original_cost' => '9999.9900',
            'salvage_value' => '0.0000',
        ]);

        // The asset came from the category's 60-month life.
        $asset->forceFill(['useful_life_months' => 12])->save();

        $calculator = app(FixedAssetDepreciationCalculator::class);
        $schedule = $calculator->schedule($asset->refresh(), []);

        $this->assertCount(12, $schedule);

        $total = Money::zero();
        foreach ($schedule as $period) {
            $total = $total->plus($period->amount);
        }

        $this->assertTrue(
            $total->equals(Money::of('9999.9900')),
            sprintf('Schedule summed to %s.', (string) $total)
        );

        // Only the last period carries the correction.
        $last = end($schedule);
        $this->assertTrue($last->isFinal);
    }

    #[Test]
    public function depreciation_refuses_to_charge_over_a_gap_in_the_schedule(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');
        $this->makePeriodFor($s['company'], '2027-03-14', 'March 2027');
        $this->makePeriodFor($s['company'], '2027-04-14', 'April 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-04-14'));
        $second = $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-04-14'));
        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-04-14'));

        /*
         * Period 2 is removed directly, behind the service's back. This is the only
         * way to reach the state the guard exists for - the application cannot create
         * it, which is exactly why the guard is a defence against a damaged table
         * rather than against a user. The unique constraints still hold, so nothing
         * here is duplicated; there is simply now a hole at period 2.
         */
        $second->delete();

        $this->expectException(ConflictException::class);

        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-05-14'));
    }

    #[Test]
    public function a_disposal_cannot_be_backdated_to_before_depreciation_that_already_posted(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        // Period 1 posts on 2027-02-14, so a disposal on 2027-01-20 would value the
        // asset using a depreciation charge from a date the disposal precedes.
        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-02-14'));

        $this->expectException(ValidationException::class);

        $this->disposal->dispose($asset->refresh(), $s['actor'], [
            'disposal_date' => '2027-01-20',
            'proceeds' => '11000.0000',
            'proceeds_account_id' => $s['chart']['bank']->getKey(),
        ]);
    }

    #[Test]
    public function a_disposal_on_the_day_depreciation_posted_is_allowed(): void
    {
        $s = $this->scenario();

        $this->makePeriodFor($s['company'], '2027-01-15', 'January 2027');
        $this->makePeriodFor($s['company'], '2027-02-14', 'February 2027');

        $asset = $this->draft($s['company'], $s['actor'], $s['category'], $s['chart'], FixedAssetAcquisitionMethod::Cash);
        $this->assets->capitalise($asset, $s['actor']);

        $this->depreciation->depreciate($asset->refresh(), $s['actor'], Carbon::parse('2027-02-14'));

        // Exactly on the last posted period end is inclusive, not an off-by-one.
        $disposal = $this->disposal->dispose($asset->refresh(), $s['actor'], [
            'disposal_date' => '2027-02-14',
            'proceeds' => '11800.0000',
            'proceeds_account_id' => $s['chart']['bank']->getKey(),
        ]);

        $this->assertSame('11800.0000', $disposal->carrying_value_at_disposal);
    }
}
