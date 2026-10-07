<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - let an account declare the currency it holds.
 *
 * This is an optional RESTRICTION, not a requirement, and the distinction is the
 * whole design.
 *
 * The ledger is denominated in the company's base currency - debit and credit on
 * journal_lines are base amounts, full stop. That is not negotiable and it is why
 * there is one set of amount columns rather than a per-currency pair.
 *
 * accounts.currency_id says what a particular account is *for*, so that a mistake
 * can be refused before it becomes a misstatement:
 *
 *  - null (the default for every existing and new account) - "no opinion". This
 *    account may be used with any transaction currency. This is the right answer
 *    for revenue, expense and equity accounts, which legitimately aggregate across
 *    currencies.
 *  - set - "this account holds money in this currency". A bank account denominated
 *    in USD must not be credited with a EUR-denominated receipt, because the
 *    balance would then be a EUR number wearing a USD label and nothing downstream
 *    could detect it. The posting services enforce the match.
 *
 * Why nullable rather than defaulting every account to the company base currency:
 * defaulting would make a bank account silently reject every foreign receipt,
 * including the legitimate ones, and the failure would appear as a puzzling
 * validation error on correct data. Null is the permissive answer and it is the
 * correct default for the majority of accounts.
 *
 * Only the balance-sheet asset/liability accounts that can actually hold foreign
 * money need this. Parent and summary accounts should be left null: they roll up
 * children of mixed currencies and restricting them would be meaningless.
 *
 * restrictOnDelete, as everywhere a currency is referenced: an account's meaning
 * must not change underneath it by the currency being removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            /*
             * The currency this account holds, or null for no restriction.
             *
             * Placed after normal_balance because that is the last column of the
             * original account type group, so existing rows keep their column order
             * and any hand-written fixture SQL stays readable.
             */
            $table->foreignId('currency_id')
                ->nullable()
                ->constrained('currencies')
                ->restrictOnDelete()
                ->after('normal_balance');
        });

        /*
         * "Which of my accounts can hold money in EUR?" - the question the
         * currency-restriction selector asks, and the only query that reads this
         * column. company_id leads because it is the tenant filter on every such
         * question.
         */
        Schema::table(
            'accounts',
            fn (Blueprint $table) => $table->index(
                ['company_id', 'currency_id'],
                'accounts_company_id_currency_id_index'
            )
        );

        /*
         * An account restricted to a currency the company cannot convert back from
         * is a genuine hazard, but "is this currency the company's base currency"
         * lives on another table, and a CHECK cannot join. So that invariant is
         * enforced in AccountCurrencyGuard at write time and tested there.
         *
         * Nothing is asserted here that the database cannot verify. An empty CHECK
         * with an encouraging comment above it would read like a guarantee while
         * constraining nothing, which is worse than saying where the rule really
         * lives.
         */
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex('accounts_company_id_currency_id_index');
            $table->dropConstrainedForeignId('currency_id');
        });
    }
};
