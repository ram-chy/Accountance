<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipt_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_receipt_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('sales_invoice_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('amount', 20, 4);

            $table->timestamps();

            $table->unique(['customer_receipt_id', 'sales_invoice_id'], 'receipt_invoice_alloc_unique');
            $table->index('sales_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipt_allocations');
    }
};
