<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - base tax amount per document line.
 *
 * THE ONE PLACE A DERIVED FIGURE IS STORED, AND WHY IT HAS TO BE
 *
 * The tax report aggregates tax across every posted document in a period and
 * reports one total per tax. Once documents can be in different currencies, those
 * documents cannot simply be added together: 1,000.0000 EUR and 90,000.0000 INR
 * summed as decimals produce a number that is not an amount in any currency.
 *
 * The obvious fix - group the report by currency - is the wrong fix. A VAT return
 * for a company that invoices in three currencies needs ONE total, and it is the
 * base currency total, because that is the functional currency the return is filed
 * in. So the report needs each line's tax converted to base, and it cannot
 * recompute that conversion from the tax rate and the document total without
 * reintroducing rounding at a different point from the one used at posting.
 *
 * Hence base_tax_amount per line: the tax in company base currency, computed once
 * at posting by the same code path and at the same rounding point that produced
 * the document's base grand total. It is stored rather than derived so the tax
 * report and the journal cannot disagree about what the tax was worth.
 *
 * Not stored on the header: the header already has base_tax_total, which is the
 * sum of these. Both exist because they answer different questions - the header
 * for reading the document, the lines for aggregating the report - and neither is
 * derivable from the other without a query the report should not have to run.
 *
 * NULLABLE, NOT DEFAULTED TO ZERO
 *
 * A NULL means "this line was posted before base tax was tracked", which is a
 * different statement from "this line had no tax". Zeroing the column for existing
 * rows would assert that every historical document had zero tax, and a tax report
 * that silently treated pre-Phase-14 documents as untaxed would be worse than one
 * that reports a coverage gap. The tax report counts NULL-bearing documents
 * separately and discloses them.
 *
 * A base-currency line's base_tax_amount equals tax_amount exactly, so the report
 * does not need to know which case it is looking at.
 */
return new class extends Migration
{
    /**
     * sales_invoice_lines, purchase_bill_lines, credit_debit_note_lines - the three
     * document line tables, which share the tax_amount / line_total shape.
     */
    private const TABLES = [
        'sales_invoice_lines',
        'purchase_bill_lines',
        'credit_debit_note_lines',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                /*
                 * This line's tax in company base currency, or null before posting
                 * (and on legacy rows). Same DECIMAL(20,4) scale as every other
                 * ledger figure, because it is a ledger figure.
                 *
                 * Placed next to tax_amount so the two read as the pair they are:
                 * tax_amount is what the customer was charged, base_tax_amount is
                 * what the ledger recorded.
                 */
                $table->decimal('base_tax_amount', 20, 4)->nullable()->after('tax_amount');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('base_tax_amount');
            });
        }
    }
};
