<?php

namespace Tests\Feature\Currency;

use App\Models\Company;
use App\Models\Currency;
use App\Models\SalesInvoice;
use App\Services\Accounting\Currency\ExchangeRateService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exchange rates: dated history, and the resolution rules that depend on it.
 *
 * THE TEST THIS FILE EXISTS TO PIN DOWN
 *
 * "The rate on date D is the newest row effective on or before D." That is one
 * sentence, it is the rule the entire phase rests on, and it has three failure modes
 * that are all invisible in casual use:
 *
 *   - taking the OLDEST matching row instead of the newest
 *   - taking the row effective EXACTLY on D instead of the last one at or before it,
 *     so a document dated between two rate changes has no rate at all
 *   - resolving against today's date instead of the document's date, which is right
 *     for every document entered today and wrong for every backdated one
 *
 * Each has a test below. None of them is obvious from reading the resolver, which is
 * why they are written against behaviour rather than against the method's shape.
 */
class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExchangeRateService $service;

    private Company $company;

    private Currency $base;

    private Currency $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ExchangeRateService::class);

        $this->base = Currency::factory()->code('IDR')->create();
        $this->foreign = Currency::factory()->code('USD')->create();

        $this->company = Company::factory()->create(['currency_id' => $this->base->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Creation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_a_rate_in_the_stored_direction(): void
    {
        $rate = $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-01-01',
            'rate' => '16,500.0000000000',
        ]);

        /*
         * One USD is worth 16,500 IDR. Storing it the other way round - 1/16500 -
         * would be the single most damaging possible mistake in this table, because
         * an invoice for 1000 USD would post as 0.06 IDR and every downstream total
         * would be wrong by a factor of 272 million while looking entirely plausible.
         */
        $this->assertSame('16500.0000000000', $rate->rate()->toDatabase());
        $this->assertSame('USD/IDR', $rate->pair());
    }

    #[Test]
    public function it_strips_grouping_separators_from_a_submitted_rate(): void
    {
        /*
         * A pasted "16,500" must not become 16.0 with a stray comma, and must not be
         * rejected for being unparseable either. The service is the last point before
         * the value reaches DECIMAL(20,10), so it is where that has to happen.
         */
        $rate = $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-01-01',
            'rate' => '16,500.25',
        ]);

        $this->assertSame('16500.2500000000', $rate->rate()->toDatabase());
    }

    #[Test]
    public function it_refuses_a_negative_rate(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-01-01',
            'rate' => '-100',
        ]);
    }

    #[Test]
    public function it_refuses_a_zero_rate(): void
    {
        /*
         * Zero is refused even though it is not negative. A zero rate would convert
         * every foreign amount to zero in the base ledger while leaving the foreign
         * column correct - a document that looks fine in its own currency and
         * contributes nothing to the trial balance.
         */
        $this->expectException(ValidationException::class);

        $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-01-01',
            'rate' => '0',
        ]);
    }

    #[Test]
    public function it_refuses_more_than_ten_decimal_places(): void
    {
        /*
         * The column is DECIMAL(20,10). A rate with eleven places would be silently
         * rounded by MySQL, so the number displayed and the number used to convert
         * would differ - a discrepancy no arithmetic check would ever surface.
         */
        $this->expectException(ValidationException::class);

        $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-01-01',
            'rate' => '1.123456789012',
        ]);
    }

    #[Test]
    public function it_refuses_two_rates_for_the_same_pair_on_the_same_day(): void
    {
        $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '16500',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '17000',
        ]);
    }

    #[Test]
    public function it_allows_the_same_pair_on_different_days(): void
    {
        $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '16500',
        ]);

        $later = $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-04-01',
            'rate' => '17000',
        ]);

        $this->assertSame('17000.0000000000', $later->rate()->toDatabase());
    }

    #[Test]
    public function the_same_pair_may_be_quoted_for_two_companies(): void
    {
        /*
         * Rates are company-scoped, and a global unique index on the pair would make
         * the second company in the system unable to record anything.
         */
        $other = Company::factory()->create(['currency_id' => $this->base->id]);

        $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '16500',
        ]);

        $theirs = $this->service->create($other, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '16000',
        ]);

        $this->assertSame('16000.0000000000', $theirs->rate()->toDatabase());
    }

    #[Test]
    public function the_reverse_pair_may_coexist_with_the_forward_pair(): void
    {
        /*
         * Both directions are independently quoted facts. A global unique index that
         * ignored direction would make it impossible to record both, which is exactly
         * the normalisation this schema refuses to do.
         */
        $forward = $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '16500',
        ]);

        $reverse = $this->service->create($this->company, [
            'from_currency_id' => $this->base->id,
            'to_currency_id' => $this->foreign->id,
            'effective_date' => '2026-03-01',
            'rate' => '0.0000606061',
        ]);

        $this->assertSame('USD/IDR', $forward->pair());
        $this->assertSame('IDR/USD', $reverse->pair());
    }

    #[Test]
    public function it_refuses_a_self_pair_at_anything_other_than_one(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->company, [
            'from_currency_id' => $this->base->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '83.5',
        ]);
    }

    #[Test]
    public function it_accepts_a_self_pair_at_exactly_one(): void
    {
        $rate = $this->service->create($this->company, [
            'from_currency_id' => $this->base->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '1',
        ]);

        $this->assertTrue($rate->rate()->isOne());
    }

    #[Test]
    public function it_refuses_an_inactive_currency(): void
    {
        $inactive = Currency::factory()->code('GBP')->inactive()->create();

        $this->expectException(ValidationException::class);

        $this->service->create($this->company, [
            'from_currency_id' => $inactive->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-03-01',
            'rate' => '1',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_resolves_the_newest_rate_at_or_before_the_document_date(): void
    {
        $this->quoteOn('2026-01-01', '16000');
        $this->quoteOn('2026-02-01', '16500');
        $this->quoteOn('2026-03-01', '17000');

        $found = $this->service->findRate($this->company, $this->foreign, $this->base, '2026-02-15');

        $this->assertNotNull($found);
        $this->assertSame('16500.0000000000', $found->rate()->toDatabase());
    }

    #[Test]
    public function it_includes_a_rate_effective_exactly_on_the_document_date(): void
    {
        $this->quoteOn('2026-01-01', '16000');
        $this->quoteOn('2026-02-01', '16500');

        $found = $this->service->findRate($this->company, $this->foreign, $this->base, '2026-02-01');

        $this->assertNotNull($found);
        $this->assertSame('16500.0000000000', $found->rate()->toDatabase());
    }

    #[Test]
    public function a_rate_stays_in_force_until_a_later_one_replaces_it(): void
    {
        $this->quoteOn('2026-01-01', '16000');

        /*
         * Eight months after the only rate was recorded, with no closer quote, the
         * January rate is still the rate. There is no expiry: a company that stops
         * quoting a pair continues to book at its last known rate rather than being
         * blocked, which is the behaviour a small business actually needs and the
         * opposite of what a "valid from / valid to" reading of the column suggests.
         */
        $found = $this->service->findRate($this->company, $this->foreign, $this->base, '2026-09-30');

        $this->assertNotNull($found);
        $this->assertSame('16000.0000000000', $found->rate()->toDatabase());
    }

    #[Test]
    public function it_finds_no_rate_before_the_first_effective_date(): void
    {
        $this->quoteOn('2026-03-01', '17000');

        $this->assertNull(
            $this->service->findRate($this->company, $this->foreign, $this->base, '2026-02-28')
        );
    }

    #[Test]
    public function it_ignores_an_inactive_rate_when_resolving(): void
    {
        $this->quoteOn('2026-01-01', '16000');

        $this->service->deactivate(
            $this->service->findRate($this->company, $this->foreign, $this->base, '2026-06-01')
        );

        $this->assertNull(
            $this->service->findRate($this->company, $this->foreign, $this->base, '2026-06-01')
        );
    }

    #[Test]
    public function an_inactive_newer_rate_does_not_hide_an_active_older_one(): void
    {
        $this->quoteOn('2026-01-01', '16000');
        $newer = $this->quoteOn('2026-02-01', '16500');

        $this->service->deactivate($newer);

        /*
         * Deactivation removes that row from resolution and leaves the one before it
         * as the rate in force. It does not leave a hole in the timeline - which is
         * the behaviour that makes deactivating a mistaken quote safe: the company
         * keeps trading, at the rate it was using before the error.
         */
        $found = $this->service->findRate($this->company, $this->foreign, $this->base, '2026-03-01');

        $this->assertNotNull($found);
        $this->assertSame('16000.0000000000', $found->rate()->toDatabase());
    }

    #[Test]
    public function it_does_not_see_another_companys_rates(): void
    {
        $other = Company::factory()->create(['currency_id' => $this->base->id]);

        $this->service->create($other, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => '2026-01-01',
            'rate' => '9999',
        ]);

        /*
         * Company isolation is enforced in the resolver rather than by a global
         * scope - see the note on Currency::scopeActive. A resolution that reached
         * across tenants would price one company's invoice at another's rate, and the
         * resulting documents would each be internally consistent.
         */
        $this->assertNull(
            $this->service->findRate($this->company, $this->foreign, $this->base, '2026-06-01')
        );
    }

    #[Test]
    public function it_resolves_nothing_when_the_company_has_no_base_currency(): void
    {
        $unconfigured = Company::factory()->create(['currency_id' => null]);

        $this->quoteOn('2026-01-01', '16000');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no base currency');

        $this->service->resolveFor($unconfigured, $this->foreign, '2026-06-01');
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_by_multiplication_not_division(): void
    {
        $this->quoteOn('2026-01-01', '16500');

        $result = $this->service->resolveFor(
            $this->company,
            $this->foreign,
            '2026-06-01',
            Money::of('1000.00')
        );

        $this->assertNotNull($result);
        $this->assertSame('16500000.0000', $result['amount']->toDatabase());
    }

    #[Test]
    public function conversion_rounds_once_to_the_ledger_scale(): void
    {
        $this->quoteOn('2026-01-01', '1.00005');

        /*
         * 100 x 1.00005 = 100.005, which needs five decimal places to write exactly.
         * The ledger is DECIMAL(20,4), so it rounds to 100.0050. Rounding must happen
         * exactly once, at the end - not once per rate component, which would
         * accumulate error across a document's lines and stop the journal balancing.
         */
        $result = $this->service->resolveFor(
            $this->company,
            $this->foreign,
            '2026-06-01',
            Money::of('100')
        );

        $this->assertNotNull($result);
        $this->assertSame('100.0050', $result['amount']->toDatabase());
    }

    /*
    |--------------------------------------------------------------------------
    | Immutability once a rate has priced a document
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_rate_that_has_priced_a_document_cannot_be_edited(): void
    {
        $this->quoteOn('2026-01-01', '16500');

        $invoice = SalesInvoice::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->foreign->id,
            'exchange_rate' => '16500.0000000000',
        ]);

        $rate = $this->service->findRate($this->company, $this->foreign, $this->base, '2026-06-01');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already been used to price a document');

        $this->service->update($rate, ['rate' => '17000']);
    }

    #[Test]
    public function an_unused_rate_may_be_edited(): void
    {
        $rate = $this->quoteOn('2026-01-01', '16500');

        $this->service->update($rate, ['rate' => '17000']);

        $this->assertSame('17000.0000000000', $rate->refresh()->rate()->toDatabase());
    }

    #[Test]
    public function a_rate_may_be_edited_when_its_documents_snapshotted_a_different_rate(): void
    {
        $rate = $this->quoteOn('2026-01-01', '16500');

        /*
         * A document priced at 17000 exists because the rate was corrected to 17000
         * and then corrected again, or the document was backdated. Either way the
         * rows that quoted 16500 are not what those documents used, so editing 16500
         * misstates nothing that is already posted.
         *
         * The alternative - refusing every edit to any pair in use - would make the
         * documented correction workflow ("record the fix on a new date")
         * unreachable, because the erroneous row itself could never be tidied.
         */
        SalesInvoice::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->foreign->id,
            'exchange_rate' => '17000.0000000000',
        ]);

        $this->service->update($rate, ['rate' => '16800']);

        $this->assertSame('16800.0000000000', $rate->refresh()->rate()->toDatabase());
    }

    #[Test]
    public function a_rate_used_by_another_company_is_not_locked_by_this_one(): void
    {
        $rate = $this->quoteOn('2026-01-01', '16500');

        $other = Company::factory()->create(['currency_id' => $this->base->id]);

        SalesInvoice::factory()->create([
            'company_id' => $other->id,
            'currency_id' => $this->foreign->id,
            'exchange_rate' => '16500.0000000000',
        ]);

        $this->service->update($rate, ['rate' => '17000']);

        $this->assertSame('17000.0000000000', $rate->refresh()->rate()->toDatabase());
    }

    /*
    |--------------------------------------------------------------------------
    | Batch resolution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function batch_resolution_returns_the_base_currency_at_a_rate_of_one(): void
    {
        $this->quoteOn('2026-01-01', '16500');

        $resolved = $this->service->resolveMany(
            $this->company,
            collect([$this->foreign, $this->base]),
            '2026-06-01'
        );

        $this->assertSame('16500.0000000000', $resolved['USD']->toDatabase());

        /*
         * Manufactured, not stored. No company needs to record a rate for its own
         * currency against itself, so the identity is supplied here - in one place -
         * and a caller can multiply by whatever it is handed.
         */
        $this->assertSame('1.0000000000', $resolved['IDR']->toDatabase());
    }

    #[Test]
    public function batch_resolution_omits_a_currency_with_no_rate_rather_than_guessing(): void
    {
        $resolved = $this->service->resolveMany(
            $this->company,
            collect([$this->foreign, $this->base]),
            '2026-06-01'
        );

        /*
         * Absent rather than defaulted. Defaulting an unknown rate to 1 would post a
         * foreign invoice at par, which is a specific and wrong number that looks
         * like a deliberate decision.
         */
        $this->assertArrayNotHasKey('USD', $resolved);
        $this->assertArrayHasKey('IDR', $resolved);
    }

    #[Test]
    public function batch_resolution_takes_the_newest_rate_per_currency(): void
    {
        $this->quoteOn('2026-01-01', '16000');
        $this->quoteOn('2026-02-01', '16500');

        $resolved = $this->service->resolveMany($this->company, collect([$this->foreign]), '2026-06-01');

        $this->assertSame('16500.0000000000', $resolved['USD']->toDatabase());
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_rate_starts_active_and_can_be_deactivated_and_reactivated(): void
    {
        $rate = $this->quoteOn('2026-01-01', '16500');

        $this->assertTrue($rate->is_active);

        $this->service->deactivate($rate);
        $this->assertFalse($rate->refresh()->is_active);

        $this->service->activate($rate);
        $this->assertTrue($rate->refresh()->is_active);
    }

    #[Test]
    public function it_refuses_to_deactivate_an_inactive_rate(): void
    {
        $rate = $this->quoteOn('2026-01-01', '16500');

        $this->service->deactivate($rate);

        $this->expectException(ValidationException::class);

        $this->service->deactivate($rate->refresh());
    }

    /**
     * A quoted rate, recorded through the service rather than the factory, so these
     * tests exercise the same write path production does.
     */
    private function quoteOn(string $date, string $rate)
    {
        return $this->service->create($this->company, [
            'from_currency_id' => $this->foreign->id,
            'to_currency_id' => $this->base->id,
            'effective_date' => $date,
            'rate' => $rate,
        ]);
    }
}
