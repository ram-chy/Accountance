<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 - a tax this company charges or recovers.
 *
 * What this table is not, and why, decides its shape.
 *
 * It is NOT a country's tax system. There is no `vat`, `gst`, `cgst`, `sgst` or
 * `igst` column, and there is no jurisdiction column. A tax is identified by a
 * company-defined code and a company-defined name, and its behaviour comes from
 * two enums the whole system already speaks:
 *
 *   tax_type          OUTPUT (collected, credits a liability) / INPUT
 *                     (recovered, debits an asset) / BOTH
 *   calculation_basis EXCLUSIVE (the amount is net) / INCLUSIVE (it is gross)
 *
 * A jurisdiction that charges two taxes on one sale - which is most of them -
 * configures two rows of tax_type OUTPUT and the engine adds them. What the tax
 * is called in that jurisdiction belongs in `name`, where it is a label rather
 * than a branch in the arithmetic.
 *
 * It is NOT a rate, and it deliberately does not carry one. A rate has an
 * effective period and therefore history: 10% last year, 12% now, both true at
 * once. Putting a single `rate` column here would mean every rate change
 * overwrites the past, and every document that relied on it would silently
 * change meaning. tax_rates holds the history instead.
 *
 * It is NOT a calculation. Nothing here is evaluated, and the engine never reads
 * this row to produce a figure without also reading tax_rates for the date being
 * calculated.
 *
 * On the rate columns: `rate` is DECIMAL(6,4), a percentage - 10.0000 means
 * 10% - which is the same convention DECIMAL(6,4) `tax_rate` already uses on
 * sales_invoice_lines and purchase_bill_lines. Six digits with four decimals
 * admits 100.00 and 99.9999 while leaving no room for a value large enough to
 * need it, and the CHECK constraint below enforces the rest. Never FLOAT: a
 * binary approximation of a tax rate is a wrong tax rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | Unique per company, never globally. Two companies each having a
             | "VAT" code is not a collision - the codes only ever mean anything
             | inside one company's chart, exactly like accounts.code. A global
             | unique index would force the second company to invent a different
             | code for the same tax and would make the code useless as a label.
             */
            $table->string('code', 50);

            $table->string('name', 255);

            $table->text('description')->nullable();

            /*
             | Both constrained in the database as well as at the request layer,
             | so a hand-written INSERT or a future code path cannot produce a tax
             | the engine cannot classify. Enum() would be a MySQL ENUM column,
             | which the rest of this schema deliberately avoids in favour of
             | varchar + CHECK: adding a value later means a table rebuild.
             */
            $table->string('tax_type', 20)->default('OUTPUT');

            $table->string('calculation_basis', 20)->default('EXCLUSIVE');

            /*
             | Absent from the model's $fillable. It is a lifecycle flag like
             | accounts.is_active, and the transition goes through TaxService so
             | it is validated and audited. A new tax is active by definition.
             */
            $table->boolean('is_active')->default(true);

            /*
             | Audit. created_by records who set the tax up and updated_by who
             | last changed it; timestamps record when.
             |
             | `updated_by` is new to this schema - no other table has it - and
             | it is here because a tax rate change is the single most
             | consequential edit this application allows, and "who moved this
             | from 10% to 12%" is the question an auditor asks first. It is set
             | by forceFill() from the authenticated actor in TaxService and is
             | never read from a request body, so there is no payload through
             | which a client can forge it.
             */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        SchemaCheck::add(
            'taxes',
            "tax_type in ('OUTPUT','INPUT','BOTH')",
            'taxes_tax_type_check'
        );

        SchemaCheck::add(
            'taxes',
            "calculation_basis in ('EXCLUSIVE','INCLUSIVE')",
            'taxes_calculation_basis_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
