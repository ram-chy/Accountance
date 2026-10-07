<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - snapshot the currency context onto a transaction document.
 *
 * WHY THE DOCUMENT, NOT JUST THE JOURNAL
 *
 * Because a document is the thing people argue about. Three months after an
 * invoice is disputed, the questions are "what currency was this in?" and "what
 * did we book?" - and both have to be answerable from the document without
 * reconstructing a journal. A draft invoice's amounts are in its transaction
 * currency and are only meaningful with the rate beside them; a posted invoice
 * additionally needs its base figures, because those are what the ledger and every
 * report speak in.
 *
 * WHAT IS ADDED, AND WHAT IS NOT
 *
 * The existing subtotal / discount_total / tax_total / grand_total columns are
 * left exactly as they are and now mean "in the document's transaction currency".
 * No base_ column replaces them and no second copy of the document's arithmetic
 * is created at line level. That is a deliberate narrowing of an earlier sketch
 * which proposed base_ mirrors of every document column: it would have added four
 * columns to three tables plus four more to three more, all of which have to stay
 * mutually consistent on every recalculation, in exchange for information that is
 * derivable. Two columns - the rate and the base grand total - carry the whole
 * conversion, and the base tax total is stored because the tax report aggregates
 * across documents in different currencies and cannot recompute it.
 *
 * WHY THE RATE IS A SNAPSHOT ON THE DOCUMENT TOO
 *
 * The same reason it is on the journal line, and the two must agree: the rate that
 * priced this document is a fact about the document. If the document re-read the
 * rate table at settlement time it would silently revalue a posted invoice, which
 * is the single most damaging thing a multi-currency system can do to an
 * accounting record. DocumentCurrencyService writes both from the same Rate
 * instance in the same operation, and JournalPostingService re-derives the line
 * rate from the document rather than resolving it again.
 *
 * NULL MEANS BASE CURRENCY, AND SAYS SO
 *
 * currency_id NULL and exchange_rate NULL is the Phase 13 shape: a single-currency
 * document whose amounts are already in the company's base currency. Every
 * existing row has this shape and stays untouched. base_grand_total is nullable for
 * a different and more specific reason: it is NULL until the document is posted,
 * because a draft has no base figure yet and writing 0.0000 would be a lie that
 * reads like an answer.
 */
return new class extends Migration
{
    /**
     * The three tables that share a document shape. Kept as a list so the three
     * get identical treatment by construction - a column added to two of the three
     * is how a document ends up convertible but not correctable.
     */
    private const TABLES = [
        'sales_invoices',
        'purchase_bills',
        'credit_debit_notes',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                /*
                 * The transaction currency, or null for base currency. Global
                 * reference data, so no company scoping - but the FK alone cannot
                 * stop a company invoicing in a currency its own base cannot
                 * convert to, and that is refused by DocumentCurrencyService.
                 */
                $table->foreignId('currency_id')
                    ->nullable()
                    ->constrained('currencies')
                    ->restrictOnDelete()
                    ->after('status');

                /*
                 * The rate snapshot: units of company base currency per one unit of
                 * currency_id. NULL with currency_id NULL.
                 */
                $table->decimal('exchange_rate', 20, 10)->nullable()->after('currency_id');

                /*
                 * The document's grand total and tax total in the company's base
                 * currency, computed once at posting and never recomputed. NULL on a
                 * draft: a draft has not been priced into the ledger yet.
                 *
                 * grand_total is stored rather than derived on read because the
                 * settlement reports and the FX gain/loss calculation need it for
                 * documents posted before this phase deployed, which have no rate to
                 * derive it from.
                 */
                $table->decimal('base_grand_total', 20, 4)->nullable()->after('grand_total');
                $table->decimal('base_tax_total', 20, 4)->nullable()->after('tax_total');
            });

            /*
             * Every document list is filtered by (company, status) and increasingly
             * by currency - the open-items reports, the currency movement report,
             * and the FX control that finds documents whose currency was
             * deactivated. currency_id trails company_id because the company filter
             * is always present and the currency one often is not.
             */
            Schema::table(
                $tableName,
                fn (Blueprint $table) => $table->index(
                    ['company_id', 'currency_id'],
                    $tableName.'_company_currency_index'
                )
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName.'_company_currency_index');
                $table->dropConstrainedForeignId('currency_id');
                $table->dropColumn([
                    'exchange_rate',
                    'base_grand_total',
                    'base_tax_total',
                ]);
            });
        }
    }
};
