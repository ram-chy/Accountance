<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 - record which configured tax a document line was calculated with.
 *
 * This is the smallest schema change that lets the tax configuration and the
 * accounting record meet, and it is one nullable column per line table. The
 * reasoning for not adding more is the substance of section 19 of the brief.
 *
 * WHAT THE LINE TABLE ALREADY HAS, and why nothing else is needed:
 *
 *   tax_rate     the rate, snapshotted, as a percentage
 *   tax_amount   the computed tax, exact decimal
 *
 * Those two columns are already the snapshot section 19 asks for. They are
 * written by DocumentCalculator when the draft is saved, copied into the journal
 * indirectly through the document totals, and never re-read from tax_rates. So
 * the historical guarantee this phase most cares about - "a posted invoice keeps
 * the 10% it was calculated with after the configured rate moves to 12%" - is
 * structurally true already, and adding a computed tax table or a tax ledger to
 * achieve it would be the second source of truth section 27 forbids.
 *
 * WHAT WAS MISSING is the link. Without it a posted line records "20.0000" with no
 * way to tell whether that was VAT, a reduced rate, a manual override, or a
 * different jurisdiction's tax, so "tax by tax code" (section 26) has nothing to
 * group by and the report can only sum everything together. `tax_id` supplies
 * exactly that: an identifier for the configured tax the rate came from.
 *
 * NULLABLE, and that is the important part. Existing rows predate the tax engine
 * and their rate was typed straight into the form, so there is no configured tax
 * behind them. The column is nullable for those rows and for the Phase 5 flows
 * that continue to work exactly as before: a line may still carry a hand-entered
 * rate with no tax_id at all. Nothing in Phase 1-9 changes behaviour.
 *
 * restrictOnDelete, so a tax that a document line names cannot be deleted out
 * from under it. TaxService refuses the deletion in the application first, with
 * a message naming deactivation; this is the backstop for a raw DELETE.
 *
 * No `tax_account_id` is added here. Both line tables' parent documents already
 * carry one, already validated against the right account type by
 * TransactionAccountResolver, and already used by the posting services. Adding a
 * second, line-level tax account would be a second answer to "where does this
 * tax go" that could disagree with the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * No separate index is added for the report's grouping. InnoDB requires an
         * index on any column a foreign key references and creates one when the
         * column is indexed for the first time, so `constrained()` already leaves
         * `tax_id` indexed on both tables. An explicitly named second index on the
         * same column would be a duplicate: it costs write throughput and disk, and
         * the optimizer has to choose between two identical candidates. The
         * migration previously added one, and its rollback then failed, because
         * dropping the index MySQL still needed for the constraint is not something
         * MySQL will do.
         */
        foreach (['sales_invoice_lines', 'purchase_bill_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('tax_id')
                    ->nullable()
                    ->constrained('taxes')
                    ->restrictOnDelete()
                    ->after('tax_rate');
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_invoice_lines', 'purchase_bill_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('tax_id');
            });
        }
    }
};
