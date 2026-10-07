<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - currency context on cash settlement, and base amounts on allocations.
 *
 * THIS IS WHERE REALISED EXCHANGE GAIN AND LOSS IS COMPUTED
 *
 * A receipt settles an invoice. Both are amounts. Neither is automatically in the
 * same currency, and if they are not, then "this invoice is fully settled" stops
 * being a comparison of two numbers and becomes a comparison of two conversions.
 *
 * The mechanism, stated before the columns:
 *
 *     carrying value   = sum over allocations of (allocation.amount * the INVOICE's
 *                        own exchange_rate snapshot)
 *     cash received    = receipt.amount * the RECEIPT's exchange_rate snapshot
 *     realised FX      = cash received - carrying value
 *
 * Two snapshots from two different dates, which is exactly right: the invoice's
 * rate is what the receivable was recorded at, and the receipt's rate is what the
 * money was actually worth. The difference between them is the gain or loss, and it
 * is realised - it is not an estimate, it happened.
 *
 * WHY ALLOCATION AMOUNTS GET THEIR OWN base_amount
 *
 * Because the FX difference must be attributed per invoice, not computed on a total.
 * One receipt can clear three invoices priced at three different rates; a single
 * receipt-level figure would produce the right total and the wrong per-invoice
 * settlement, so the customer's statement and the invoice's outstanding balance
 * would disagree with the ledger. Storing the converted amount per allocation makes
 * the attribution a fact rather than a recomputation, and it is what
 * RealizedFxService reads.
 *
 * WHY ALLOCATIONS DO NOT GET THEIR OWN currency_id
 *
 * Because an allocation cannot have a currency different from its parent - that is
 * enforced by PaymentAllocationService refusing a cross-currency allocation
 * outright, rather than by a column that would have two valid values to choose
 * between. One currency per settlement document, inherited by its allocations, is a
 * stricter and simpler rule than "the same, usually".
 *
 * WHY base_amount IS STORED RATHER THAN COMPUTED AT SETTLEMENT TIME
 *
 * Same reason as the document rate: rates are mutable history. An allocation's
 * converted value is fixed when the settlement posts, and the invoice it settles is
 * never re-priced afterwards. A company that changes its rate source in June must
 * not retroactively change what a February receipt settled.
 */
return new class extends Migration
{
    /**
     * customer_receipts, supplier_payments - the two settlement documents, which
     * share the amount / payment_account_id shape.
     */
    private const PAYMENT_TABLES = [
        'customer_receipts',
        'supplier_payments',
    ];

    /**
     * The allocation tables, which share the amount shape. No currency column, as
     * explained above.
     */
    private const ALLOCATION_TABLES = [
        'customer_receipt_allocations',
        'supplier_payment_allocations',
    ];

    public function up(): void
    {
        foreach (self::PAYMENT_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                /*
                 * The currency the cash was actually received or paid in. This is the
                 * side that can differ from the invoice's: a USD invoice settled by
                 * a customer who wires EUR is the canonical case, and it is why
                 * realised FX exists in this schema at all.
                 */
                $table->foreignId('currency_id')
                    ->nullable()
                    ->constrained('currencies')
                    ->restrictOnDelete()
                    ->after('amount');

                /*
                 * The rate snapshot for the cash leg, same convention as
                 * documents: units of base currency per one unit of currency_id.
                 */
                $table->decimal('exchange_rate', 20, 10)->nullable()->after('currency_id');

                /*
                 * `amount` converted to company base currency, computed once at
                 * posting.
                 *
                 * NULL on drafts and on legacy rows - a draft receipt has not been
                 * priced into the ledger yet, and a Phase 13 receipt was by
                 * definition base currency at rate 1, for which base_amount equals
                 * amount and no conversion was performed. Both are treated as "the
                 * allocation amounts are already base" by the FX service, which is
                 * what keeps every existing settlement posting byte-identical.
                 */
                $table->decimal('base_amount', 20, 4)->nullable()->after('exchange_rate');
            });

            Schema::table(
                $tableName,
                fn (Blueprint $table) => $table->index(
                    ['company_id', 'currency_id'],
                    $tableName.'_company_currency_index'
                )
            );
        }

        foreach (self::ALLOCATION_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                /*
                 * allocation.amount converted to base at the INVOICE's rate - the
                 * carrying value this allocation settles, which is what the realised
                 * FX difference is measured against.
                 *
                 * Nullable for the same reason as base_amount above. There is no
                 * index here on purpose: allocations are always read through their
                 * parent receipt or payment, and the existing unique on
                 * (parent, document) plus the index on the document id already cover
                 * both access paths.
                 */
                $table->decimal('base_amount', 20, 4)->nullable()->after('amount');
            });
        }
    }

    public function down(): void
    {
        foreach (self::ALLOCATION_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('base_amount');
            });
        }

        foreach (self::PAYMENT_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName.'_company_currency_index');
                $table->dropConstrainedForeignId('currency_id');
                $table->dropColumn(['exchange_rate', 'base_amount']);
            });
        }
    }
};
