<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_bill_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_bill_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            $table->string('description', 1000)->nullable();

            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_cost', 20, 4)->default(0);
            $table->decimal('discount', 20, 4)->default(0);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);

            /*
             * The expense account this line is charged to. Purchases may
             * span multiple expense categories (utilities, rent, supplies),
             * so it is per-line and must be an expense account.
             */
            $table->foreignId('expense_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['purchase_bill_id', 'line_number']);
            $table->index('expense_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_bill_lines');
    }
};
