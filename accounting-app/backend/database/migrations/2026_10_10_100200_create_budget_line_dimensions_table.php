<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budget_line_dimensions')) {
            return;
        }
        Schema::create('budget_line_dimensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_line_id');
            $table->unsignedBigInteger('financial_dimension_id');
            $table->unsignedBigInteger('financial_dimension_value_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('budget_line_id')->references('id')->on('budget_lines')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('financial_dimension_id')->references('id')->on('financial_dimensions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('financial_dimension_value_id')->references('id')->on('financial_dimension_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['budget_line_id', 'financial_dimension_id'], 'bld_bl_fd_unique');
            $table->index(['financial_dimension_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_line_dimensions');
    }
};
