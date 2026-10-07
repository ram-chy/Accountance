<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - dated exchange rates.
 *
 * WHAT A RATE MEANS, AND IT MEANS ONE THING
 *
 * `rate` is the number of units of `to_currency_id` equal to ONE unit of
 * `from_currency_id` on `effective_date`. So a row of
 * (USD -> INR, 2026-10-03, 83.500000) reads "on the 3rd of October 2026, one US
 * dollar bought eighty-three rupees and fifty paise", and a foreign amount is
 * converted with
 *
 *     base = foreign * rate
 *
 * There is no second direction anywhere in the schema. A pair is stored the way it
 * would be quoted - the currency you have, then the currency you want - rather than
 * being normalised into some canonical base pair, because normalising would make
 * the stored number mean something different from the number the user typed, and a
 * rate table whose rows disagree with the form that created them is worse than no
 * rate table at all.
 *
 * WHY DECIMAL(20,10) AND NOT THE LEDGER'S DECIMAL(20,4)
 *
 * The monetary scale in config/accounting.php is four places, which is the right
 * answer for amounts and the wrong answer for a rate. IDR to USD is roughly
 * 0.000062, and a rate stored at four places would round that to 0.0001 - a 61%
 * error in the conversion factor, produced silently, by a precision choice made for
 * a different kind of number. Ten places covers every rate a reporting currency
 * pair produces and keeps the working product (foreign amount x rate) exact at the
 * ledger's scale.
 *
 * WHY NOT A FLOAT
 *
 * Same reason as every other monetary column, and stronger here: a rate is
 * multiplied by an amount, so a one-ulp error in the rate becomes a visible error
 * in a posted journal. See App\Support\Rate for the value object that owns the
 * arithmetic.
 *
 * WHY DATED, AND WHY NOT OVERWRITTEN
 *
 * `effective_date` makes this an effective-dated history rather than a mutable
 * current rate, which is what lets a document snapshot the rate it was recorded at
 * and stay correct afterwards. Changing "today's" rate is a matter of inserting a
 * later row, never of updating an earlier one. ExchangeRateService refuses to edit
 * a rate that a posted document already used, and the unique index below is what
 * actually makes "one rate per pair per day" a database fact rather than a rule a
 * concurrent pair of requests can both pass.
 *
 * THE OVERLAP PROBLEM IS NOT SOLVABLE HERE, AND IS NOT PRETENDED TO BE
 *
 * "These two date ranges must not intersect" cannot be expressed as a MySQL unique
 * index, which is why the tax_rates table solves the same shape of problem in the
 * service layer. This table takes the simpler rule that is enforceable in the
 * database: at most one row per (company, pair, day). A caller that wants to
 * change the rate from a given day forward inserts a new row from that day, and the
 * resolver takes the newest row at or before the date being priced - the same
 * "latest effective_from wins" rule Tax::rateOn() already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();

            /*
             * Company-scoped, unlike currencies. Two companies may legitimately
             * hold different rates for the same pair on the same day - a spread, a
             * different rate source, a rate negotiated with one bank - and forcing
             * one global row would mean the first company to record a rate decides
             * for everybody.
             */
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The currency the rate is quoted FROM, and the one it is quoted TO.
             * Both are restrictOnDelete: a currency row that a rate quotes cannot
             * disappear, because the quote is the only record of what the company
             * believed the rate was. Currencies are never deleted in the first
             * place - they are deactivated - so this is the backstop under that
             * absence, exactly as journal_lines.account_id is.
             */
            $table->foreignId('from_currency_id')
                ->constrained('currencies')
                ->restrictOnDelete();

            $table->foreignId('to_currency_id')
                ->constrained('currencies')
                ->restrictOnDelete();

            /*
             * The day this rate took effect, inclusive. Resolution is "the newest
             * row whose effective_date is on or before the date being priced", so a
             * rate stays in force until a later one replaces it and a rate can be
             * backdated to price a document that is entered late.
             */
            $table->date('effective_date');

            $table->decimal('rate', 20, 10);

            /*
             * Where the number came from. Free text, nullable, never interpreted.
             * It exists so a person reading a gain or loss three years later can
             * see that the rate came off the bank's own feed rather than being
             * typed in - which is the difference between an auditable conversion
             * and an unexplained one.
             */
            $table->string('source', 100)->nullable();

            /*
             * Withdrawn from use without touching its history, for the same reason
             * a tax rate can be deactivated rather than deleted: a rate that priced
             * a posted document must stay readable. Inactive rows are ignored by
             * the resolver, so the correct answer for a newly priced document is
             * never a rate somebody has withdrawn.
             */
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            /*
             * The concurrency guarantee for rate configuration. Two users saving
             * "USD to INR on the 3rd" at the same moment would both pass a
             * Rule::unique check and both insert, after which "the rate on the 3rd"
             * has two answers and the resolver has to break a tie it should never
             * face. One row per pair per day per company.
             */
            $table->unique(
                ['company_id', 'from_currency_id', 'to_currency_id', 'effective_date'],
                'exchange_rates_pair_day_unique'
            );

            /*
             * The resolver's actual query: which rows are candidates for a pair on
             * a date, newest first. company_id leads because every resolution is
             * company-scoped and the company filter is the tenant boundary.
             */
            $table->index(
                ['company_id', 'from_currency_id', 'to_currency_id', 'effective_date'],
                'exchange_rates_resolution_index'
            );

            // "Which currencies does this company have rates for?"
            $table->index(['company_id', 'to_currency_id'], 'exchange_rates_company_to_index');
        });

        /*
         * A rate of zero would make every conversion of an amount in the source
         * currency into a base amount of zero - and division by a rate, which the
         * resolver never does but which a future reader would assume is possible,
         * would fail. Negative rates have no meaning in any real market.
         *
         * Enforced in the database rather than only in the request, because this is
         * the one value on which every posted foreign-currency amount depends.
         */
        SchemaCheck::add('exchange_rates', 'rate > 0', 'exchange_rates_rate_positive_check');

        /*
         * A rate from a currency to itself is legal and meaningful - it is exactly
         * 1, and it is how a company says "this currency is also my functional
         * currency" - so this is NOT constrained away. It is recorded here because
         * the alternative reading of a self-pair is the mistake this table exists
         * to prevent.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
