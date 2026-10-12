<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->foreignId('to_currency_id')->constrained('currencies')->cascadeOnDelete();

            $table->date('effective_date');
            $table->decimal('rate', 20, 10);

            $table->boolean('is_active')->default(true);
            $table->string('source', 100)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'from_currency_id', 'to_currency_id', 'effective_date']);
            $table->index('company_id');
            $table->index('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
