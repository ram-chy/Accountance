<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_dimension_values')) {
            return;
        }
        Schema::create('financial_dimension_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('financial_dimension_id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('financial_dimension_id')->references('id')->on('financial_dimensions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unique(['financial_dimension_id', 'code']);
            $table->index(['financial_dimension_id', 'is_active'], 'fdv_fd_id_is_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_dimension_values');
    }
};
