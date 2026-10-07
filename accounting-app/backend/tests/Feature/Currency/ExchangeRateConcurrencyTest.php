<?php

namespace Tests\Feature\Currency;

use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\Accounting\Currency\ExchangeRateService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two writers, one day, one rate.
 *
 * THE RACE THIS FILE EXISTS TO PIN DOWN
 *
 * The rule is "one rate per (company, pair, day)". A `Rule::unique` validation
 * check cannot enforce it: two requests can both read "no rate yet", both pass
 * the check, and both INSERT. The read and the write are not atomic, and no
 * application-level check can close that window without a lock the application
 * does not hold.
 *
 * So the unique index is the authority, and these tests exercise it as the
 * authority rather than trusting the service to have checked first:
 *
 *   1. A duplicate INSERT straight to the table - skipping the service entirely -
 *      is refused by the database, leaving exactly one authoritative row.
 *
 *   2. When the service loses that race against an already-committed row, the
 *      collision reaches the caller as a validation error (a 422), not as an
 *      uncaught QueryException (a 500). A person who typed the same rate twice
 *      gets a message, not a stack trace.
 *
 * A true wall-clock race cannot be staged deterministically in a test process, so
 * the "committed winner" is written directly and the loser is the operation under
 * test. That is the same database state a losing racer would find, and it is the
 * state the unique index has to handle.
 */
class ExchangeRateConcurrencyTest extends TestCase
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

    #[Test]
    public function a_duplicate_committed_insert_is_rejected_by_the_database(): void
    {
        // The winner: a writer that committed its rate for the pair and day.
        DB::table('exchange_rates')->insert([$this->row('16500.0000000000')]);

        /*
         * The loser: a second writer that observed "no rate yet" before the winner
         * committed. Nothing in the application serialises the read and the write,
         * so the INSERT reaches the table and only the unique index stands in the
         * way. If it does not, "the rate on the 3rd" has two answers.
         */
        try {
            DB::table('exchange_rates')->insert([$this->row('17000.0000000000')]);
            $this->fail('A duplicate rate was accepted; the unique index is not enforcing one rate per pair per day.');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }

        $this->assertSame(1, $this->rateCount());
    }

    #[Test]
    public function a_lost_race_reaches_the_caller_as_a_validation_error_not_a_server_error(): void
    {
        // The winner committed first; the loser is the service call below, which
        // now collides with data it never saw when it started.
        DB::table('exchange_rates')->insert([$this->row('16500.0000000000')]);

        try {
            $this->service->create($this->company, [
                'from_currency_id' => $this->foreign->getKey(),
                'to_currency_id' => $this->base->getKey(),
                'effective_date' => '2026-03-01',
                'rate' => '17000',
            ]);

            $this->fail('The service accepted a duplicate rate that the database should have rejected.');
        } catch (ValidationException $e) {
            // The collision is reported against the field a person would fix.
            $this->assertArrayHasKey('effective_date', $e->errors());
        }

        // And the winner is still the one authoritative rate; the loser left nothing.
        $this->assertSame(1, $this->rateCount());
    }

    private function row(string $rate): array
    {
        return [
            'company_id' => $this->company->getKey(),
            'from_currency_id' => $this->foreign->getKey(),
            'to_currency_id' => $this->base->getKey(),
            'effective_date' => '2026-03-01',
            'rate' => $rate,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function rateCount(): int
    {
        return ExchangeRate::query()
            ->where('company_id', $this->company->getKey())
            ->where('from_currency_id', $this->foreign->getKey())
            ->where('to_currency_id', $this->base->getKey())
            ->where('effective_date', '2026-03-01')
            ->count();
    }
}
