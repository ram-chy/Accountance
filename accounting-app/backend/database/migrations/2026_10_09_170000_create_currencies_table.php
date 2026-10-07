<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - the currency master.
 *
 * REFERENCE DATA, NOT TENANT DATA
 *
 * There is deliberately no company_id here, and that is a decision rather than an
 * omission. Every column is a fact about a currency itself: its ISO 4217 code, its
 * name, its symbol and how many decimal places its minor unit has. None of it is
 * something one company asserts and another disagrees with - "the US dollar has
 * two decimal places" is not a per-tenant opinion. Making the table company-scoped
 * would mean 180 identical reference rows per tenant, a UNIQUE(code) constraint
 * that can only ever be (company_id, code), and a cross-company currency list that
 * could drift into two spellings of the same currency - at which point "convert at
 * today's USD rate" stops having one answer.
 *
 * What IS company-scoped is everything that *uses* a currency: exchange rates,
 * documents, accounts and the company's own base-currency choice. That is where
 * the tenant boundary lives, and it is enforced on those tables.
 *
 * WHY code IS char(3) AND NOT A VARCHAR
 *
 * ISO 4217 alphabetic codes are exactly three characters. char(3) makes that a
 * physical property of the column rather than a validation rule that a future
 * writer could forget, and it pads no faster than varchar for the storage this
 * table will ever hold. The UNIQUE index below is the real guarantee that the
 * same currency cannot be defined twice, which is the failure that would actually
 * matter.
 *
 * decimal_precision IS DATA, NOT A CONSTANT
 *
 * "Two decimal places" is true of most currencies and false of JPY (0), BHD, JOD,
 * KWD and OMR (3). A system that assumed two everywhere would offer to book a
 * fraction of a yen and round a dinar's third place away without saying so, so the
 * minor unit is stored and the request layer enforces it. See
 * StoreCurrencyRequest for where it is applied.
 *
 * NO SEEDER
 *
 * The brief forbids one, and it is also unnecessary: a currency is created through
 * the API like any other master record, and a company with no currencies simply
 * cannot transact in a foreign currency until an administrator creates the ones it
 * needs. The cost of that is a cold start for a fresh install; the benefit is that
 * no hidden process writes to a financial table. The trade is documented in
 * PHASE_14_REPORT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();

            /*
             * ISO 4217 alphabetic code, uppercase. Stored as given by the
             * application layer, which upper-cases on the model so a factory, a
             * console command and an HTTP request all produce the same value -
             * 'us' and 'US' must not become two currencies.
             */
            $table->char('code', 3);

            $table->string('name', 100);

            // Presentation only. Never parsed: an amount is a decimal string, and a
            // symbol is not a number.
            $table->string('symbol', 10)->nullable();

            /*
             * Decimal places in this currency's minor unit. 0 for JPY, 3 for the
             * Gulf dinars, 2 for almost everything else.
             *
             * unsignedTinyInteger rather than a CHECK alone, because MySQL will not
             * re-check a CHECK constraint when the column definition changes and a
             * silent widening of the range would be the more surprising outcome.
             * The CHECK below keeps it to the ISO range.
             */
            $table->unsignedTinyInteger('decimal_precision')->default(2);

            /*
             * Lifecycle flag, absent from the model's fillable for the same reason
             * accounts.is_active and taxes.is_active are: retiring a currency is a
             * service method with its own authorization and its own audit row, not
             * a column a client can flip in an update.
             *
             * There is no DELETE. A currency that a posted document or an exchange
             * rate referenced must stay readable forever, so the reversible
             * lifecycle ends at deactivation.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * The one guarantee that matters: a currency is defined once. Without
             * this, two concurrent creates of USD would both pass a
             * Rule::unique check and leave the rate table with an ambiguous "the
             * USD" to join against.
             */
            $table->unique('code');

            // The currency picker lists active currencies alphabetically.
            $table->index(['is_active', 'code']);
        });

        SchemaCheck::add(
            'currencies',
            'decimal_precision <= 4',
            'currencies_precision_check'
        );

        /*
         * char(3) pads short codes rather than truncating them, so 'US' is stored
         * as 'US ' and length comparison against 3 would pass. Trimming first is
         * what catches it.
         *
         * Note what is deliberately NOT checked here: `code = upper(code)`. Under
         * this connection's case-insensitive collation that comparison is true of
         * every row, so it would look like a guarantee while constraining nothing.
         * Case normalisation is instead guaranteed structurally - the unique index
         * above treats 'us' and 'US' as the same key under the same collation, so
         * the second one cannot be inserted - and the model upper-cases on write so
         * the stored value is already canonical. A check that appears to enforce
         * something it does not is worse than an honest comment about where the
         * guarantee really lives.
         */
        SchemaCheck::add(
            'currencies',
            'char_length(trim(code)) = 3',
            'currencies_code_format_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
