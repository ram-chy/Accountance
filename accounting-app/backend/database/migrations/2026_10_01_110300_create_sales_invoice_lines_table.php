<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_invoice_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | 1-based line number, unique within the invoice, so the order
             * the user entered the lines is preserved and cannot be silently
             * lost if the client submits them without a stable key.
             */
            $table->unsignedSmallInteger('line_number');

            $table->string('description', 1000)->nullable();

            /*
             | Quantity and unit price are stored explicitly. A sale may be
             * for a non-integer quantity or a price with cents, and the invoice
             * line must record the exact factors that produced the line total.
             * The rate uses DECIMAL so line totals are exact.
             */
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 20, 4)->default(0);
            $table->decimal('discount', 20, 4)->default(0);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);

            /*
             * The revenue account for this specific line. Sales may be split
             * across several revenue accounts (different product lines), so it
             * is per-line rather than per-invoice. Must belong to the same
             * company, be active, and be an appropriate revenue account.
             */
            $table->foreignId('revenue_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['sales_invoice_id', 'line_number']);
            $table->index('revenue_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
    }
};
