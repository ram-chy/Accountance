<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - turn companies.currency_id into a real foreign key.
 *
 * The column already existed. Phase 1 added `currency_id` to companies with a
 * comment saying it would hold the company's base currency, and it has been
 * nullable and unconstrained ever since - deliberately, because at the time there
 * was no currencies table for it to point at.
 *
 * There are now two currencies tables' worth of reasons to keep it, and the
 * important thing about this migration is what it does NOT do: it does not
 * backfill, default, or guess.
 *
 * - No default currency is assigned to any existing company. Inventing one would
 *   mean silently deciding that every company in the world books in USD, and any
 *   report produced before a human chose a base currency would be an artefact of
 *   that guess rather than of the data.
 * - No currency is seeded, per the phase brief.
 *
 * So after this migration every company still has currency_id = NULL, and that
 * NULL now means something precise: "base currency not configured". A company in
 * that state keeps working exactly as it did in Phase 13 - single-currency
 * documents post with an implicit rate of 1 - and cannot create a
 * foreign-currency document until an administrator sets the base currency. That
 * is a deliberate availability trade: the failure is a clear validation message at
 * the point of use, not a wrong number in a posted journal.
 *
 * restrictOnDelete, matching exchange_rates: a currency a company converted into
 * must remain readable, because it defines the meaning of every base amount that
 * company has ever posted. Currencies are deactivated, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('currency_id')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete();
        });

        /*
         * No explicit index here, and that is a correction rather than an omission.
         *
         * MySQL automatically creates a supporting index for a foreign key when no
         * existing index has the FK column as its leftmost column, so an explicit
         * companies_currency_id_index would be a second identical index - and a
         * rollback that tried to drop it would fail with error 1553, "Cannot drop
         * index: needed in a foreign key constraint", because dropping it would
         * leave the FK unsupported.
         *
         * The query this was originally added for - "which companies still need a
         * base currency" - does not need a dedicated index anyway. It is a control
         * check run per company, over a table that already carries
         * index (is_active, name), and the automatic FK index serves the join.
         */
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
        });
    }
};
