<?php

namespace Tests\Feature\Currency;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyFxSetting;
use App\Models\Currency;
use App\Services\Accounting\Currency\RealizedFxService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Realised foreign exchange: which side of the FX accounts a settlement lands on.
 *
 * WHY THIS FILE IS MOSTLY ABOUT SIGNS
 *
 * Getting a realised FX result backwards produces entries that every structural check
 * in this system passes. The journal balances. The amounts are non-negative. The debit
 * and credit columns are used correctly. Nothing is ever flagged, because a mirrored
 * FX posting is arithmetically perfect and completely wrong - every gain recorded as
 * a loss and vice versa, in every currency, forever.
 *
 * So the arithmetic is asserted in full, from the rate change that causes it through
 * to the account and column it lands in, rather than trusting a one-line sign check.
 */
class RealizedFxServiceTest extends TestCase
{
    use RefreshDatabase;

    private RealizedFxService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RealizedFxService::class);

        $base = Currency::factory()->code('IDR')->create();

        $this->company = Company::factory()->create(['currency_id' => $base->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Direction
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_higher_settlement_rate_is_a_gain(): void
    {
        /*
         * 100 USD receivable at 16,500 carries 1,650,000 IDR.
         * Collected at 17,000 the cash is worth 1,700,000 IDR.
         * The company is 50,000 better off than the books said.
         */
        $result = $this->service->compute(Money::of('1700000'), Money::of('1650000'));

        $this->assertTrue($result->isGain());
        $this->assertFalse($result->isLoss());
        $this->assertSame('50000.0000', $result->difference()->toDatabase());
        $this->assertSame('50000.0000', $result->amount()->toDatabase());
    }

    #[Test]
    public function a_lower_settlement_rate_is_a_loss(): void
    {
        /*
         * The mirror image, and the case that a sign error would also get wrong.
         * Collected at 15,000 the cash is worth 1,500,000 against a carrying value of
         * 1,650,000 - the company is 150,000 worse off.
         */
        $result = $this->service->compute(Money::of('1500000'), Money::of('1650000'));

        $this->assertTrue($result->isLoss());
        $this->assertFalse($result->isGain());
        $this->assertSame('-150000.0000', $result->difference()->toDatabase());
    }

    #[Test]
    public function the_posted_amount_is_always_positive(): void
    {
        /*
         * The direction is carried by which column gets filled, not by the sign of the
         * amount. A negative amount in a journal line is refused by the database, so
         * returning the signed figure here would turn a logic error into a constraint
         * violation on the posting path - a far worse place to discover it.
         */
        $loss = $this->service->compute(Money::of('1500000'), Money::of('1650000'));

        $this->assertTrue($loss->amount()->isPositive());
        $this->assertSame('150000.0000', $loss->amount()->toDatabase());
    }

    #[Test]
    public function an_unchanged_rate_produces_no_fx_result(): void
    {
        $result = $this->service->compute(Money::of('1650000'), Money::of('1650000'));

        $this->assertTrue($result->isNone());
        $this->assertFalse($result->isGain());
        $this->assertFalse($result->isLoss());
        $this->assertTrue($result->amount()->isZero());
    }

    #[Test]
    public function a_sub_minor_unit_difference_is_still_a_result(): void
    {
        /*
         * One rupiah on a million-rupee settlement. It must not be discarded as
         * "just rounding": it is a real difference between two real conversions, and
         * dropping small FX results until they are material would make the gain/loss
         * account disagree with the movement it exists to explain.
         */
        $result = $this->service->compute(Money::of('1650000.0001'), Money::of('1650000'));

        $this->assertTrue($result->isGain());
        $this->assertSame('0.0001', $result->difference()->toDatabase());
    }

    /*
    |--------------------------------------------------------------------------
    | Which amount clears the balance
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_balance_is_cleared_at_its_carrying_value_not_the_settlement_value(): void
    {
        /*
         * The single most consequential line in the file. The receivable is credited
         * at what the books say it is worth, and the gap between that and the cash
         * goes to FX. Clearing it at the settlement amount instead would leave a
         * residual balance equal to the FX result - an account balance with no
         * invoice behind it, which looks exactly like a customer who underpaid.
         */
        $result = $this->service->compute(Money::of('1700000'), Money::of('1650000'));

        $this->assertSame('1650000.0000', $result->amountToClear()->toDatabase());
    }

    #[Test]
    public function a_loss_still_clears_the_balance_at_its_carrying_value(): void
    {
        $result = $this->service->compute(Money::of('1500000'), Money::of('1650000'));

        $this->assertSame('1650000.0000', $result->amountToClear()->toDatabase());
    }

    /*
    |--------------------------------------------------------------------------
    | Account resolution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_gain_posts_to_the_configured_gain_account(): void
    {
        $gain = CompanyFxSetting::factory()->configured($this->company)->create();

        $account = $this->service->resolveAccount($this->company, true);

        $this->assertSame($gain->realized_gain_account_id, $account->getKey());
    }

    #[Test]
    public function a_loss_posts_to_the_configured_loss_account(): void
    {
        $setting = CompanyFxSetting::factory()->configured($this->company)->create();

        $account = $this->service->resolveAccount($this->company, false);

        $this->assertSame($setting->realized_loss_account_id, $account->getKey());
    }

    #[Test]
    public function gain_and_loss_are_different_accounts(): void
    {
        $setting = CompanyFxSetting::factory()->configured($this->company)->create();

        $this->assertNotSame(
            $setting->realized_gain_account_id,
            $setting->realized_loss_account_id
        );
    }

    #[Test]
    public function an_unconfigured_company_cannot_post_a_settlement(): void
    {
        CompanyFxSetting::factory()->create(['company_id' => $this->company->id]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('realised FX accounts');

        $this->service->resolveAccount($this->company, true);
    }

    #[Test]
    public function a_company_with_no_fx_settings_row_cannot_post_a_settlement(): void
    {
        /*
         * The row is meant to always exist, so this is the state after something went
         * wrong rather than a normal one. It is tested because "the row is missing" and
         * "the row exists but is empty" must behave identically from the outside - if
         * only the second threw, a company in the first state would get a null account
         * and a posting that silently went nowhere.
         */
        $this->expectException(ValidationException::class);

        $this->service->resolveAccount($this->company, true);
    }

    #[Test]
    public function the_unconfigured_message_names_what_to_do(): void
    {
        try {
            $this->service->resolveAccount($this->company, true);

            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('fx_settings');

            $this->assertStringContainsString('gain account', $message);
            $this->assertStringContainsString('loss account', $message);
        }
    }

    #[Test]
    public function one_companys_fx_accounts_are_not_used_by_another(): void
    {
        $mine = CompanyFxSetting::factory()->configured($this->company)->create();

        $theirs = Company::factory()->create(['currency_id' => $this->company->currency_id]);

        $theirSetting = CompanyFxSetting::factory()->configured($theirs)->create();

        /*
         * Both configured, and each resolves to its own accounts. FX accounts are
         * company policy, and a company posting another tenant's gain account would
         * put the result in a chart of accounts that does not describe its business.
         */
        $this->assertSame(
            $mine->realized_gain_account_id,
            $this->service->resolveAccount($this->company, true)->getKey()
        );

        $this->assertSame(
            $theirSetting->realized_gain_account_id,
            $this->service->resolveAccount($theirs, true)->getKey()
        );

        $this->assertNotSame(
            $mine->realized_gain_account_id,
            $theirSetting->realized_gain_account_id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reporting readiness
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function can_post_foreign_settlement_reflects_the_configuration(): void
    {
        $this->assertFalse($this->service->canPostForeignSettlement($this->company));

        CompanyFxSetting::factory()->configured($this->company)->create();

        $this->assertTrue($this->service->canPostForeignSettlement($this->company));
    }

    /*
    |--------------------------------------------------------------------------
    | The full entry, reconstructed from rates
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_rate_change_between_invoice_and_receipt_produces_the_expected_entry(): void
    {
        CompanyFxSetting::factory()->configured($this->company)->create();

        $receiptBase = Money::of('100')->times(Money::of('17000'));   // 1,700,000
        $invoiceBase = Money::of('100')->times(Money::of('16500'));   // 1,650,000

        $result = $this->service->compute($receiptBase, $invoiceBase);

        $this->assertTrue($result->isGain());

        /*
         * The two lines of the settlement posting, side by side, so the test asserts
         * the entry rather than the difference:
         *
         *   Debit  Bank          1,700,000   (cash, at the receipt's rate)
         *   Credit Receivable   1,650,000   (cleared at the invoice's carrying value)
         *   Credit FX Gain         50,000
         *
         * 1,700,000 = 1,650,000 + 50,000. The journal balances, the receivable lands on
         * zero, and the 50,000 is on the income statement rather than unexplained.
         */
        $this->assertSame('1700000.0000', $receiptBase->toDatabase());
        $this->assertSame('1650000.0000', $result->amountToClear()->toDatabase());
        $this->assertSame(
            $receiptBase->toDatabase(),
            bcadd($result->amountToClear()->toDatabase(), $result->amount()->toDatabase(), 4)
        );
    }

    #[Test]
    public function a_rate_fall_produces_a_loss_entry_that_balances(): void
    {
        CompanyFxSetting::factory()->configured($this->company)->create();

        $receiptBase = Money::of('100')->times(Money::of('15000'));   // 1,500,000
        $invoiceBase = Money::of('100')->times(Money::of('16500'));   // 1,650,000

        $result = $this->service->compute($receiptBase, $invoiceBase);

        $this->assertTrue($result->isLoss());

        /*
         *   Debit  Bank          1,500,000
         *   Debit  FX Loss        150,000
         *   Credit Receivable   1,650,000
         *
         * Same shape with the FX line on the other side. The loss is debited rather
         * than credited because the shortfall has to be made up somewhere, and a loss
         * is an expense.
         */
        $this->assertSame(
            bcadd($receiptBase->toDatabase(), $result->amount()->toDatabase(), 4),
            $result->amountToClear()->toDatabase()
        );
    }

    #[Test]
    public function the_description_distinguishes_gain_loss_and_none(): void
    {
        $this->assertSame(
            'Realised foreign exchange gain',
            $this->service->compute(Money::of('110'), Money::of('100'))->description()
        );

        $this->assertSame(
            'Realised foreign exchange loss',
            $this->service->compute(Money::of('90'), Money::of('100'))->description()
        );

        $this->assertSame(
            'No realised foreign exchange difference',
            $this->service->compute(Money::of('100'), Money::of('100'))->description()
        );
    }

    #[Test]
    public function a_gain_account_must_be_income_and_a_loss_account_an_expense(): void
    {
        $setting = CompanyFxSetting::factory()->configured($this->company)->create();

        $gain = Account::find($setting->realized_gain_account_id);
        $loss = Account::find($setting->realized_loss_account_id);

        /*
         * Not asserted by the service - enforced when the settings are configured, and
         * asserted here because an FX result posted to a revenue or asset account
         * balances and is still wrong. The accounts are in the chart of accounts
         * where a gain belongs and a loss belongs, which is the minimum that makes the
         * income statement mean anything.
         */
        $this->assertSame(AccountType::Revenue, $gain->account_type);
        $this->assertSame(AccountType::Expense, $loss->account_type);
    }
}
