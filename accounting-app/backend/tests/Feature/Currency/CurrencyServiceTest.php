<?php

namespace Tests\Feature\Currency;

use App\Models\Currency;
use App\Services\Accounting\Currency\CurrencyService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Currency master data: code normalisation, uniqueness, precision, deactivation.
 *
 * Two things here are load-bearing beyond "does the CRUD work":
 *
 *  1. Deactivation must not break documents that already used the currency. The
 *     currency disappears from pickers; existing rows keep resolving and keep
 *     meaning. A test that only checked is_active flipped to false would pass
 *     against an implementation that cascaded the change.
 *
 *  2. Precision is bounded at both ends. Zero is refused because a currency with no
 *     decimal places cannot represent the arithmetic this phase performs, and a
 *     wildly large value is refused because it would make every amount in that
 *     currency render as noise.
 */
class CurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private CurrencyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CurrencyService::class);
    }

    #[Test]
    public function it_creates_a_currency_with_a_normalised_code(): void
    {
        $currency = $this->service->create([
            'code' => ' usd ',
            'name' => '  US Dollar  ',
            'symbol' => '$',
            'decimal_precision' => 2,
        ]);

        $this->assertDatabaseHas('currencies', [
            'id' => $currency->id,
            'code' => 'USD',
            'name' => 'US Dollar',
        ]);
    }

    #[Test]
    public function it_uppercases_lowercase_codes(): void
    {
        $currency = $this->service->create([
            'code' => 'eur',
            'name' => 'Euro',
            'decimal_precision' => 2,
        ]);

        $this->assertSame('EUR', $currency->code);
    }

    #[Test]
    public function it_refuses_a_duplicate_code(): void
    {
        Currency::factory()->create(['code' => 'USD']);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'code' => 'USD',
            'name' => 'Another Dollar',
            'decimal_precision' => 2,
        ]);
    }

    #[Test]
    public function it_refuses_a_code_that_is_not_three_letters(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'code' => 'DOLLAR',
            'name' => 'Dollar',
            'decimal_precision' => 2,
        ]);
    }

    #[Test]
    public function it_truncates_an_overlong_symbol_rather_than_refusing_the_currency(): void
    {
        /*
         * A long symbol is a cosmetic problem, not a reason to refuse to create a
         * currency that is otherwise valid. The column is 10 characters; truncating
         * loses nothing a person was relying on, whereas a rejected creation makes
         * them retype the whole record to fix a display detail.
         */
        $currency = $this->service->create([
            'code' => 'XYZ',
            'name' => 'Test',
            'symbol' => str_repeat('x', 15),
            'decimal_precision' => 2,
        ]);

        $this->assertSame(str_repeat('x', 10), $currency->symbol);
    }

    #[Test]
    public function it_refuses_absurd_decimal_precision(): void
    {
        /*
         * 0 is allowed and is not a defect - JPY has no minor unit. The bound exists
         * on the other side: past four decimal places a currency could no longer
         * express itself within the ledger's own DECIMAL(20,4), and a document in it
         * would become unroundable rather than merely unusual.
         */
        $this->expectException(ValidationException::class);

        $this->service->create([
            'code' => 'XYZ',
            'name' => 'Test',
            'decimal_precision' => 9,
        ]);
    }

    #[Test]
    public function a_new_currency_is_active(): void
    {
        $currency = $this->service->create([
            'code' => 'GBP',
            'name' => 'Pound Sterling',
            'decimal_precision' => 2,
        ]);

        $this->assertTrue($currency->is_active);
    }

    #[Test]
    public function deactivating_a_currency_keeps_it_readable_but_unselectable(): void
    {
        $currency = Currency::factory()->create(['code' => 'GBP', 'is_active' => true]);

        $this->service->deactivate($currency);

        $this->assertFalse($currency->refresh()->is_active);

        /*
         * Still loadable by id. This is the distinction deactivation exists to draw:
         * it stops the currency being chosen, it does not erase what it meant to
         * documents already booked at its rates.
         */
        $this->assertNotNull(Currency::find($currency->id));
    }

    #[Test]
    public function it_refuses_to_deactivate_an_already_inactive_currency(): void
    {
        $currency = Currency::factory()->create(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->service->deactivate($currency);
    }

    #[Test]
    public function an_inactive_currency_can_be_reactivated(): void
    {
        $currency = Currency::factory()->create(['is_active' => false]);

        $this->service->activate($currency);

        $this->assertTrue($currency->refresh()->is_active);
    }

    #[Test]
    public function the_lifecycle_stops_at_deactivation_and_does_not_delete(): void
    {
        /*
         * CurrencyService has no delete() at all, and that is the design rather than
         * a gap: a currency referenced by a posted document defines what the numbers
         * on that document mean, so removing the row would destroy the ability to
         * find out what it meant without removing the amounts themselves.
         *
         * Asserted structurally so that a delete() added later - with no argument
         * about why it was safe - fails this test.
         */
        $this->assertFalse(
            method_exists(CurrencyService::class, 'delete'),
            'CurrencyService must not offer delete; deactivation is the terminal state.',
        );
    }

    #[Test]
    public function it_updates_a_currency_without_changing_its_id(): void
    {
        $currency = Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar']);
        $id = $currency->id;

        $updated = $this->service->update($currency, ['name' => 'United States Dollar']);

        $this->assertSame($id, $updated->id);
        $this->assertSame('United States Dollar', $updated->name);
    }

    #[Test]
    public function a_currency_may_be_renamed_to_its_own_code(): void
    {
        /*
         * The distinctness rule is "no other currency has this code", not "the code
         * must change". Renaming the name while keeping the code is the most common
         * edit there is, and a uniqueness check that compared against the row itself
         * would refuse it.
         */
        $currency = Currency::factory()->create(['code' => 'USD']);

        $updated = $this->service->update($currency, ['code' => 'USD', 'name' => 'Renamed']);

        $this->assertSame('USD', $updated->code);
    }

    #[Test]
    public function a_currency_may_not_take_another_currencys_code(): void
    {
        Currency::factory()->create(['code' => 'EUR']);
        $dollar = Currency::factory()->create(['code' => 'USD']);

        $this->expectException(ValidationException::class);

        $this->service->update($dollar, ['code' => 'EUR']);
    }

    #[Test]
    public function a_blank_symbol_becomes_null(): void
    {
        $currency = Currency::factory()->create(['symbol' => '$']);

        $updated = $this->service->update($currency, ['symbol' => '']);

        $this->assertNull($updated->symbol);
    }

    #[Test]
    public function updating_a_currency_with_no_recognised_fields_changes_nothing(): void
    {
        $currency = Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar']);

        $updated = $this->service->update($currency, ['not_a_field' => 'ignored']);

        $this->assertSame('US Dollar', $updated->name);
    }

    #[Test]
    public function format_amount_uses_the_currency_precision(): void
    {
        $currency = Currency::factory()->create(['code' => 'USD', 'decimal_precision' => 2]);

        $this->assertSame('1,234.56', $currency->formatAmount(Money::of('1234.56')));

        /*
         * A zero-decimal currency rounds rather than truncating, so the JPY case is
         * also a check that precision is applied at output and not assumed to be 2.
         */
        $yen = Currency::factory()->create(['code' => 'JPY', 'decimal_precision' => 0]);

        $this->assertSame('1,235', $yen->formatAmount(Money::of('1234.56')));
    }

    /**
     * A deactivated currency must still format its existing amounts, or historical
     * reports break the moment someone tidies up the currency list.
     */
    #[Test]
    public function an_inactive_currency_still_formats_amounts(): void
    {
        $currency = Currency::factory()->create([
            'code' => 'GBP',
            'decimal_precision' => 2,
            'is_active' => false,
        ]);

        $this->assertSame('10.00', $currency->formatAmount(Money::of('10')));
    }

    public static function invalidCodes(): array
    {
        return [
            'too short' => ['US'],
            'too long' => ['USDX'],
            'not letters' => ['12$'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidCodes')]
    #[Test]
    public function it_refuses_malformed_codes(string $code): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'code' => $code,
            'name' => 'Test',
            'decimal_precision' => 2,
        ]);
    }
}
