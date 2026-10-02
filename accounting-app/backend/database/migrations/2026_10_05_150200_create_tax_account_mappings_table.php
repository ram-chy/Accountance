<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 - which chart-of-accounts accounts a tax posts to.
 *
 * Two nullable columns rather than two tables, because a tax is either an output
 * tax, an input tax, or both, and "both" is the case that makes a one-table-per-
 * side design fall apart: BOTH would need a row in each table, with no way to
 * keep the two in step.
 *
 * The mapping reuses existing accounts. It creates none, and there is no account
 * hierarchy here: `output_account_id` and `input_account_id` point into the same
 * `accounts` table the chart of accounts already owns, and the account-type rule
 * is the one TransactionAccountResolver has always applied -
 *
 *   output  LIABILITY   tax collected is money held for a tax authority
 *   input   ASSET      tax recovered is a receivable from one
 *
 * Stating that here as a comment rather than as a CHECK constraint is a
 * considered choice. MySQL cannot write a CHECK that inspects another table, so
 * the only possible database-level guarantee would be on this table's own columns
 * - and `output_account_id in (select id from accounts where account_type = ...)`
 * is not something a CHECK constraint can express. The rule is enforced by
 * TaxAccountMappingService through TransactionAccountResolver at write time, and
 * again at calculation time, which is the same two-layer arrangement the Phase 5
 * transaction flows already rely on for their receivable and payable accounts.
 *
 * Both columns are nullable: a tax may be configured before anyone decides where
 * its money lands. The engine does not require a mapping to calculate - it only
 * refuses to book one, and the refusal names the missing account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_account_mappings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * One mapping per tax. Enforced by unique(tax_id) below rather than
             * by (company_id, tax_id): tax_id is already unique across companies
             * because a tax row belongs to exactly one company, so the pair adds
             * nothing, and a single-column unique is the constraint a future
             * lookup can rely on without knowing the tenant.
             */
            $table->foreignId('tax_id')
                ->constrained('taxes')
                ->restrictOnDelete();

            /*
             * Restricted rather than cascaded for the same reason as
             * tax_rates.tax_id. An account with a tax mapping attached has been
             * used as the destination for tax movements; removing the account
             * would orphan that fact. AccountService already refuses to delete an
             * account with journal history, and this is the configuration-layer
             * equivalent of the same rule.
             */
            $table->foreignId('output_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->foreignId('input_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique('tax_id');
        });

        /*
         * A mapping for a tax that applies to neither side is a contradiction the
         * service also rejects, but it costs nothing to make the database refuse
         * it too: a row with both columns NULL records no mapping at all, and
         * "delete this row and re-create it when you have the accounts" is the
         * wrong instruction for a user who has simply not finished configuring.
         */
        SchemaCheck::add(
            'tax_account_mappings',
            'output_account_id is not null or input_account_id is not null',
            'tax_account_mappings_at_least_one_check'
        );

        /*
         * The same account may not be both sides of one tax. Tax collected and tax
         * recovered net to zero in a single account, which is exactly the
         * presentation that makes a tax position unreadable - and the reason the
         * chart of accounts separates them in the first place.
         */
        SchemaCheck::add(
            'tax_account_mappings',
            'output_account_id is null or input_account_id is null or output_account_id <> input_account_id',
            'tax_account_mappings_distinct_accounts_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_account_mappings');
    }
};
