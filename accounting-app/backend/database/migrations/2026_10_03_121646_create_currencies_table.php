<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 3);
            $table->string('name', 100);
            $table->string('symbol', 10)->nullable();
            $table->unsignedTinyInteger('decimal_precision')->default(2);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_base')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('company_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
