<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_payment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('purchase_bill_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('amount', 20, 4);

            $table->timestamps();

            $table->unique(['supplier_payment_id', 'purchase_bill_id'], 'payment_bill_alloc_unique');
            $table->index('purchase_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
