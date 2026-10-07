<?php

namespace Tests\Feature\Currency;

use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Services\Accounting\Currency\CurrencyService;
use App\Services\Accounting\Currency\DocumentCurrencyService;
use App\Services\Accounting\Currency\ExchangeRateService;
use App\Services\Accounting\Currency\TransactionCurrency;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The currency context a document is booked in.
 *
 * WHAT IS WORTH TESTING HERE
 *
 * Not the arithmetic - that is Rate's job and ExchangeRateServiceTest covers it -
 * but the two decisions that are decisions rather than calculations:
 *
 *  1. An explicit choice of the company's OWN base currency is normalised to base,
 *     with a NULL rate, rather than treated as a foreign currency at rate 1. The two
 *     are stored differently, so a document that said "USD" when the base is USD
 *     would otherwise produce journal lines carrying a rate that was never quoted.
 *
 *  2. An account with no declared currency accepts anything, while one that does
 *     declare a currency must match exactly. The permissive default is what lets an
 *     existing single-currency chart of accounts work unchanged.
 */
class DocumentCurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentCurrencyService $service;

    private ExchangeRateService $rates;

    private Company $company;

    private Currency $base;

    private Currency $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rates = app(ExchangeRateService::class);
        $this->service = app(DocumentCurrencyService::class);

        $this->base = Currency::factory()->code('IDR')->create();
        $this->foreign = Currency::factory()->code('USD')->create();

        $this->company = Company::factory()->create(['currency_id' => $this->base->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Base currency
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function no_currency_means_the_base_currency_at_an_identity_rate(): void
    {
        $context = $this->service->resolve($this->company, null, '2026-06-01');

        $this->assertFalse($context->isForeign());
        $this->assertNull($context->currency);
        $this->assertTrue($context->rate->isOne());
        $this->assertNull($context->rateToPersist());
    }

    #[Test]
    public function a_base_currency_document_needs_no_rate_configured(): void
    {
        $company = Company::factory()->create(['currency_id' => null]);

        /*
         * A company with no base currency can still book in its own amounts. The
         * absence of a configured base means "this company has not chosen one yet",
         * not "this company cannot transact" - and the no-seeder rule guarantees
         * every freshly created company starts in exactly this state.
         */
        $context = $this->service->resolve($company, null, '2026-06-01');

        $this->assertFalse($context->isForeign());
    }

    #[Test]
    public function explicitly_choosing_the_own_base_currency_is_treated_as_base(): void
    {
        $context = $this->service->resolve($this->company, $this->base->id, '2026-06-01');

        /*
         * The distinction the whole test exists for. isForeign() false and a NULL
         * persisted rate - not a foreign document quoting 1. A rate of 1 on a journal
         * line claims someone quoted a rate; nobody did.
         */
        $this->assertFalse($context->isForeign());
        $this->assertNull($context->rateToPersist());
    }

    /*
    |--------------------------------------------------------------------------
    | Foreign currency
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_foreign_currency_resolves_to_its_rate_on_the_document_date(): void
    {
        $this->quote('2026-01-01', '16000');
        $this->quote('2026-02-01', '16500');

        $context = $this->service->resolve($this->company, $this->foreign->id, '2026-02-15');

        $this->assertTrue($context->isForeign());
        $this->assertSame('USD', $context->code());
        $this->assertSame('16500.0000000000', $context->rateToPersist());
    }

    #[Test]
    public function a_foreign_currency_persists_its_rate(): void
    {
        $this->quote('2026-01-01', '16000');

        $context = $this->service->resolve($this->company, $this->foreign->id, '2026-02-01');

        $this->assertSame('16000.0000000000', $context->rateToPersist());
    }

    #[Test]
    public function a_backdated_document_is_priced_at_its_own_date_not_todays(): void
    {
        $this->quote('2020-01-01', '14000');
        $this->quote('2026-01-01', '16500');

        $context = $this->service->resolve($this->company, $this->foreign->id, '2020-06-01');

        /*
         * The single most consequential thing this class does. Resolving against
         * today would return 16500 for a document from 2020 and misstate it by 18%,
         * with nothing anywhere in the output to indicate an error.
         */
        $this->assertSame('14000.0000000000', $context->rateToPersist());
    }

    #[Test]
    public function a_foreign_currency_with_no_rate_on_or_before_its_date_is_refused(): void
    {
        $this->quote('2026-06-01', '16500');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No exchange rate is configured from USD to IDR');

        $this->service->resolve($this->company, $this->foreign->id, '2026-01-01');
    }

    #[Test]
    public function the_missing_rate_error_names_the_pair_and_the_date(): void
    {
        try {
            $this->service->resolve($this->company, $this->foreign->id, '2026-01-01');

            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('currency_id');

            $this->assertStringContainsString('USD', $message);
            $this->assertStringContainsString('IDR', $message);
            $this->assertStringContainsString('2026-01-01', $message);
        }
    }

    #[Test]
    public function an_inactive_currency_may_not_price_a_new_document(): void
    {
        $this->quote('2026-01-01', '16000');
        app(CurrencyService::class)->deactivate($this->foreign);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exists but is inactive');

        $this->service->resolve($this->company, $this->foreign->id, '2026-06-01');
    }

    #[Test]
    public function a_nonexistent_currency_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->resolve($this->company, 999999, '2026-06-01');
    }

    #[Test]
    public function a_foreign_currency_needs_a_configured_base_currency(): void
    {
        $unconfigured = Company::factory()->create(['currency_id' => null]);
        $this->quote('2026-01-01', '16000');

        /*
         * "Convert into the base currency" has no meaning without a base currency, so
         * there is nothing to resolve and nothing to guess. This is the practical face
         * of the no-seeder decision: a company has to choose a base currency before
         * it can trade in anything else.
         */
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no base currency');

        $this->service->resolve($unconfigured, $this->foreign->id, '2026-06-01');
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_base_currency_context_converts_to_itself(): void
    {
        $context = $this->service->resolve($this->company, null, '2026-06-01');

        $amount = Money::of('1234.56');

        $this->assertSame(
            '1234.5600',
            $this->service->toBase($amount, $context)->toDatabase()
        );
    }

    #[Test]
    public function a_foreign_currency_context_multiplies_by_its_rate(): void
    {
        $this->quote('2026-01-01', '16500');

        $context = $this->service->resolve($this->company, $this->foreign->id, '2026-06-01');

        $this->assertSame(
            '16500000.0000',
            $this->service->toBase(Money::of('1000'), $context)->toDatabase()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Account currency compatibility
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_account_with_no_declared_currency_accepts_a_foreign_document(): void
    {
        $account = Account::factory()->create(['company_id' => $this->company->id, 'currency_id' => null]);

        $this->quote('2026-01-01', '16000');
        $context = $this->service->resolve($this->company, $this->foreign->id, '2026-06-01');

        /*
         * The permissive default, and the reason an existing chart of accounts needs
         * no migration to become multi-currency capable. "I don't care which currency"
         * and "I am USD-only" are different statements and the column records which.
         */
        $this->assertTrue($account->acceptsCurrency($context->effectiveCurrency()->id));

        $this->assertAccepts($account, $context);
    }

    #[Test]
    public function an_account_declared_for_the_transaction_currency_is_accepted(): void
    {
        $account = Account::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->foreign->id,
        ]);

        $this->quote('2026-01-01', '16000');
        $context = $this->service->resolve($this->company, $this->foreign->id, '2026-06-01');

        $this->assertAccepts($account, $context);
    }

    #[Test]
    public function an_account_declared_for_another_currency_is_refused(): void
    {
        $eur = Currency::factory()->code('EUR')->create();

        $account = Account::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'Bank EUR',
            'currency_id' => $eur->id,
        ]);

        $this->quote('2026-01-01', '16000');
        $context = $this->service->resolve($this->company, $this->foreign->id, '2026-06-01');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('holds [EUR] and cannot be used with [USD]');

        $this->service->assertAccountAccepts($account, $context, 'account_id');
    }

    #[Test]
    public function an_account_declared_for_the_base_currency_refuses_a_foreign_document(): void
    {
        $account = Account::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'Local Bank',
            'currency_id' => $this->base->id,
        ]);

        $this->quote('2026-01-01', '16000');
        $context = $this->service->resolve($this->company, $this->foreign->id, '2026-06-01');

        /*
         * The asymmetry that makes the rule strict rather than loose. A USD-only
         * account does not accept IDR amounts because they are "close enough" - and
         * a base-currency account does not accept USD either, since a document in USD
         * posts its base amounts to this account and would leave the foreign column
         * with no matching balance.
         */
        $this->expectException(ValidationException::class);

        $this->service->assertAccountAccepts($account, $context, 'account_id');
    }

    #[Test]
    public function an_account_declared_for_the_base_currency_accepts_a_base_document(): void
    {
        $account = Account::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->base->id,
        ]);

        $context = $this->service->resolve($this->company, null, '2026-06-01');

        $this->assertAccepts($account, $context);
    }

    /*
    |--------------------------------------------------------------------------
    | Batch resolution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function batch_resolution_covers_base_and_foreign_together(): void
    {
        $this->quote('2026-01-01', '16000');

        $resolved = $this->service->resolveMany(
            $this->company,
            new Collection([$this->base, $this->foreign]),
            '2026-06-01'
        );

        $this->assertFalse($resolved['IDR']->isForeign());
        $this->assertTrue($resolved['USD']->isForeign());
        $this->assertSame('16000.0000000000', $resolved['USD']->rateToPersist());
    }

    #[Test]
    public function batch_resolution_omits_currencies_without_a_rate(): void
    {
        $resolved = $this->service->resolveMany(
            $this->company,
            new Collection([$this->base, $this->foreign]),
            '2026-06-01'
        );

        $this->assertArrayHasKey('IDR', $resolved);
        $this->assertArrayNotHasKey('USD', $resolved);
    }

    #[Test]
    public function batch_resolution_resolves_nothing_without_a_base_currency(): void
    {
        $unconfigured = Company::factory()->create(['currency_id' => null]);

        $resolved = $this->service->resolveMany(
            $unconfigured,
            new Collection([$this->foreign]),
            '2026-06-01'
        );

        $this->assertSame([], $resolved);
    }

    /**
     * Assert that an account is accepted, counting "did not throw" as an assertion.
     *
     * assertAccountAccepts signals acceptance by returning and refusal by throwing,
     * so a plain call registers nothing and PHPUnit reports the test as risky. The
     * fail() below is the real assertion: it converts a refusal into a failure with
     * the service's own message attached, which is the part worth seeing.
     */
    private function assertAccepts(Account $account, TransactionCurrency $context): void
    {
        try {
            $this->service->assertAccountAccepts($account, $context, 'account_id');
        } catch (ValidationException $e) {
            $this->fail('Expected the account to be accepted, but it was refused: '.$e->getMessage());
        }

        $this->assertTrue(true, 'The account was accepted without a validation error.');
    }

    /**
     * A quoted rate through the real service, so these tests share the write path
     * production uses rather than reaching for the factory.
     */
    private function quote(string $date, string $rate): void
    {
        $this->rates->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => Carbon::parse($date),
            'rate' => $rate,
        ]);
    }
}
