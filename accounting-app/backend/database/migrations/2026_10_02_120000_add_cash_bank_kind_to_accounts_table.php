<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 - identify an account as holding cash or as being a bank account.
 *
 * One nullable column, and nothing else.
 *
 * The alternative designs were considered and rejected rather than skipped:
 *
 *  - A new AccountType (CASH, BANK). Rejected because AccountType drives the
 *    trial balance, balance sheet and P&L through account_type filters; adding
 *    two more types would make every one of those queries responsible for
 *    excluding them, and cash and bank are assets first.
 *  - Reusing is_system. Rejected because that column means "a module owns this
 *    account" and its migration comment names Accounts Receivable and Tax
 *    Payable alongside Cash - marking it would classify receivables as cash.
 *  - A companion table holding only the classification. Rejected because "is
 *    this account cash or bank" is a property of the account, and burying it in
 *    a side table means every eligibility check needs a join to answer.
 *
 * Nullable because the ordinary case is null: most accounts are not cash or
 * bank. It is CHECKed to the enum so a raw SQL write cannot introduce a kind
 * that no application code understands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            /*
             * CASH or BANK, or null for an ordinary account.
             *
             * Indexed with company_id because this is the only query that selects
             * the column: "which cash/bank accounts can this company post
             * against". The composite index serves the eligibility check and the
             * account listing without either being a table scan.
             */
            $table->string('cash_bank_kind', 20)
                ->nullable()
                ->after('account_type');
        });

        Schema::table(
            'accounts',
            fn (Blueprint $table) => $table->index(['company_id', 'cash_bank_kind'], 'accounts_company_id_cash_bank_kind_index')
        );

        SchemaCheck::add(
            'accounts',
            "cash_bank_kind is null or cash_bank_kind in ('CASH','BANK')",
            'accounts_cash_bank_kind_check'
        );
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex('accounts_company_id_cash_bank_kind_index');
            $table->dropColumn('cash_bank_kind');
        });
    }
};
