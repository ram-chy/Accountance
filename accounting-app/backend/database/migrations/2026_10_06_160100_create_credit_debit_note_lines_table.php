<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 - credit/debit note lines.
 *
 * THIS IS THE NOTE'S OWN CALCULATION, NOT A REFERENCE TO ONE
 *
 * Every monetary column here is written by DocumentCalculator from the note's own
 * submitted lines, exactly as sales_invoice_lines and purchase_bill_lines are
 * written. That is the whole of the historical tax guarantee: a note posted at 10%
 * keeps tax_rate = 10 and tax_amount = the figure it charged, and no report or
 * service ever re-reads tax_rates to recompute it. There is no view, no generated
 * column and no join back to the source line that could reintroduce that.
 *
 * A LINE IS NOT REQUIRED TO NAME THE SOURCE LINE IT ADJUSTS
 *
 * source_line_id is nullable on purpose. Document-level adjustment is a real and
 * common case - "credit the whole order", "we under-billed this customer" - and
 * requiring a line reference would force an accountant to split a single
 * document-level correction across source lines that have no line of their own.
 * When it IS supplied, CreditDebitNoteAdjustmentService enforces that the line
 * belongs to the note's own source document and that the adjusted quantity has
 * not already been consumed by another posted note.
 *
 * The same two-nullable-FKs argument as the header applies here: sales_invoice_id
 * and purchase_bill_line_id instead of a (type, id) pair, so the reference is a
 * real foreign key and the CHECK below can prove the two kinds never coexist.
 *
 * `unit_price`, NOT `unit_cost`
 *
 * Purchase bill lines call their price column unit_cost, and this table could
 * have matched. It does not, because one note table serves all four note types
 * and the request body is the same shape for each of them. DocumentCalculator
 * already takes the price field's name as a parameter precisely so the arithmetic
 * can be shared while each document keeps the input name its own users know; the
 * stored column here is unit_price for every type, and the purchase branch passes
 * 'unit_price' as the input field too. A caller never has to remember which of
 * two field names applies to which note type.
 *
 * `account_id`, NOT revenue_account_id / expense_account_id
 *
 * Same reasoning. A sales note credits revenue, a purchase note debits expense,
 * and TransactionAccountResolver already enforces which account types each role
 * accepts - so a single column, validated per note type, cannot be pointed at the
 * wrong kind of account. Two columns would make it possible to store an expense
 * account on a sales note and rely on nothing noticing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_debit_note_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('credit_debit_note_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | 1-based, unique within the note, preserving the order the user
             | entered the lines in. Same convention as both existing line tables.
             */
            $table->unsignedSmallInteger('line_number');

            /*
             | The source line this note line adjusts, when the adjustment is
             | line-level. Null for a document-level adjustment. RESTRICT, so a
             | posted invoice's line cannot be deleted while a posted note still
             | references it - though in practice the invoice itself is already
             | undeletable, because its own header is referenced by the note.
             */
            $table->foreignId('sales_invoice_line_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('purchase_bill_line_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->string('description', 1000)->nullable();

            /*
             | The note's own factors. These are the numbers the note was priced
             | from, snapshotted for the same reason sales_invoice_lines snapshots
             | them: the note must remain explainable after the source document, the
             | tax configuration and the price list have all moved on.
             */
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 20, 4)->default(0);
            $table->decimal('discount', 20, 4)->default(0);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);

            /*
             | The configured tax this line was calculated with, for the same
             | reason and with the same limitation as sales_invoice_lines.tax_id:
             | set only when exactly one tax applies, because the column holds one
             | id and a multi-tax line attributing itself to one of several taxes
             | would be a wrong answer rather than a partial one. A multi-tax line
             | reports as unattributed in the tax report, which is why that report
             | has an unattributed bucket at all.
             */
            $table->foreignId('tax_id')
                ->nullable()
                ->constrained('taxes')
                ->restrictOnDelete()
                ->after('tax_rate');

            $table->decimal('line_total', 20, 4)->default(0);

            /*
             | The revenue account for a sales note line, the expense account for
             | a purchase note line. Appropriateness is decided by
             | TransactionAccountResolver against the note's own type, at draft
             * time and again at posting time.
             */
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['credit_debit_note_id', 'line_number']);

            /*
             | The per-source-line adjustment sum, which runs on every create,
             | update and post of a note line-level note. Same reasoning as the
             | header's source indexes.
             */
            $table->index('sales_invoice_line_id');
            $table->index('purchase_bill_line_id');
            $table->index('account_id');
            $table->index('tax_id');
        });

        /*
         | A line adjusts one source line or none, never both. Absent for the
         | document-level case, which is why this is "at most one" rather than the
         | header's "exactly one" - a note line with no source line is a valid
         * row.
         */
        SchemaCheck::add(
            'credit_debit_note_lines',
            'not ((sales_invoice_line_id is not null) and (purchase_bill_line_id is not null))',
            'credit_debit_note_lines_single_source_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_debit_note_lines');
    }
};
