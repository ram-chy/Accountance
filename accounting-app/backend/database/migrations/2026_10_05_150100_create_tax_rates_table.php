<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 - one effective-dated rate for one tax.
 *
 * This table is what makes a tax's history honest. Several rows can be active at
 * once, because "active" here means "not superseded", not "current": a company
 * that charged 10% through March and 12% from April holds two rows, and a
 * document dated either day resolves to the right one.
 *
 * `effective_to` is NULL on the newest rate and a date on superseded ones. NULL
 * rather than a far-future sentinel date, because a sentinel has to be chosen and
 * a wrong choice silently truncates history; NULL means "open ended" with no
 * value to get wrong.
 *
 * A rate row is never rewritten to change which period it covers. The rule for
 * "which rate applies on date D" is `effective_from <= D AND (effective_to IS
 * NULL OR effective_to >= D)`, and two rows must not both satisfy it - the
 * overlap is rejected by TaxRateService and by the CHECK-free design below,
 * which is deliberate: a unique index cannot express "ranges that do not
 * intersect" in MySQL without a range lock the schema should not depend on.
 *
 * On posted documents: nothing here is referenced by a posted invoice or bill.
 * A document line snapshots the rate it was calculated with into its own
 * tax_rate column, so changing or superseding a row here cannot alter a figure
 * that is already in the accounting record. That is the whole historical
 * integrity guarantee, and it is why this table needs no restrictOnDelete from
 * the document side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Restricted, not cascaded. A rate that has been used to calculate a
             * posted document is a fact about that document's arithmetic; deleting
             * the rate row would leave a configured tax with no explanation of
             * what it used to charge. Deactivation is the way to retire a rate -
             * set is_active = false, which removes it from resolution without
             * removing the history.
             */
            $table->foreignId('tax_id')
                ->constrained('taxes')
                ->restrictOnDelete();

            /*
             * A percentage, not a fraction: 10.0000 means 10%. A fraction would
             * make 0.10 and 10 mean the same thing to a human reading the
             * database, and the tax_rate columns already on sales_invoice_lines
             * and purchase_bill_lines use the percentage convention - two
             * conventions for one quantity is one too many.
             *
             * DECIMAL(6,4): 100.0000 fits, 1000.0000 does not. Never FLOAT or
             * DOUBLE: a binary approximation of a tax rate produces tax amounts
             * that are wrong in a way no ledger check can detect.
             */
            $table->decimal('rate', 6, 4);

            $table->date('effective_from');

            /*
             * Nullable: NULL means the rate is still open-ended. See the class
             * docblock for why this is not a far-future date.
             */
            $table->date('effective_to')->nullable();

            /*
             * Absent from $fillable, like taxes.is_active. It answers "may this
             * rate be used for a new document", which is a lifecycle decision
             * routed through TaxRateService.
             */
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            /*
             * The resolver's only query: the active rates of one tax, then a date
             * comparison. company_id leads because no rate is ever looked up
             * without a company, and leading with it lets the planner use the
             * index alone rather than filtering tax_id out of a wider scan.
             */
            $table->index(['company_id', 'tax_id', 'is_active'], 'tax_rates_company_tax_active_index');
        });

        SchemaCheck::add(
            'tax_rates',
            'rate >= 0 and rate < 100',
            'tax_rates_rate_range_check'
        );

        SchemaCheck::add(
            'tax_rates',
            'effective_to is null or effective_to >= effective_from',
            'tax_rates_date_order_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
