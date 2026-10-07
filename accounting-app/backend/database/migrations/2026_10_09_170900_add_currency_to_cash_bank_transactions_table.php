<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - currency context on cash and bank movements.
 *
 * WHAT IS DELIBERATELY NOT IN THIS MIGRATION
 *
 * A cross-currency bank transfer - move 10,000 EUR out of a EUR account and
 * 10,900 USD into a USD account - needs more than a currency on this row. It needs
 * two legs at two rates, which means the transaction carries a rate for its
 * destination leg that is not derivable from its source leg, and it needs the
 * resulting FX gain or loss posted. That is a second journal from one document,
 * and CashBankPostingService currently produces exactly one balanced journal per
 * transaction.
 *
 * So Phase 14 does not pretend to support it. CashBankPostingService refuses a
 * transfer between accounts whose restricted currencies differ, with a message
 * that says to book it as two transactions plus an explicit FX reclassification.
 * A partial implementation that converted the amount at one rate and posted one
 * journal would be worse: it would balance, and it would be wrong in a way that
 * only surfaces at year end.
 *
 * WHAT IS SUPPORTED
 *
 * A single-currency movement where the amount is stated in that currency and
 * converted once: a foreign-currency bank feed line, a cash withdrawal from a
 * foreign till, a foreign-currency transfer between two accounts of the same
 * currency. base_amount is what the ledger records; amount is what the user typed.
 *
 * account currency RESTRICTION, NOT account currency ASSIGNMENT
 *
 * accounts.currency_id states what an account holds. This row's currency_id states
 * what this movement is denominated in. CashBankPostingService requires them to
 * agree whenever the account declares one, which is what prevents a EUR feed line
 * landing in a USD-denominated bank account. Accounts with no declared currency
 * (null) accept anything, so existing accounts keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_bank_transactions', function (Blueprint $table) {
            /*
             * The currency this movement is denominated in, or null for base.
             * One currency for the whole transaction, not one per leg - see the
             * header comment on why a two-leg cross-currency transfer is refused
             * rather than half-supported.
             */
            $table->foreignId('currency_id')
                ->nullable()
                ->constrained('currencies')
                ->restrictOnDelete()
                ->after('amount');

            /*
             * The rate snapshot: base per one unit of currency_id, applied to the
             * single leg this transaction type produces.
             */
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('currency_id');

            /*
             * The amount in company base currency, computed once at posting and
             * written to both journal legs' debit/credit. NULL on drafts and on
             * legacy rows, which are base currency at rate 1.
             */
            $table->decimal('base_amount', 20, 4)->nullable()->after('exchange_rate');
        });

        /*
         * The bank reconciliation matches a statement line to a transaction, and
         * matching across currencies would be an arithmetic error: a USD statement
         * line cannot clear a EUR transaction. The composite serves "which
         * transactions in currency X are still unmatched", which is the query the
         * reconciliation screen runs for a foreign feed.
         */
        Schema::table('cash_bank_transactions', function (Blueprint $table) {
            $table->index(
                ['company_id', 'currency_id', 'status'],
                'cash_bank_txn_company_currency_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cash_bank_transactions', function (Blueprint $table) {
            $table->dropIndex('cash_bank_txn_company_currency_status_index');

            /*
             * dropConstrainedForeignId, not dropColumn: MySQL refuses to drop a
             * column that a foreign key still references
             * ("needed in a foreign key constraint"), so the constraint has to go
             * first. Every down() that removes a constrained column in this phase
             * uses this method for the same reason.
             */
            $table->dropConstrainedForeignId('currency_id');

            $table->dropColumn(['exchange_rate', 'base_amount']);
        });
    }
};
