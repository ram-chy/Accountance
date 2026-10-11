<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_line_dimensions')) {
            return;
        }
        Schema::create('journal_line_dimensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_line_id');
            $table->unsignedBigInteger('financial_dimension_id');
            $table->unsignedBigInteger('financial_dimension_value_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('journal_line_id')->references('id')->on('journal_lines')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('financial_dimension_id')->references('id')->on('financial_dimensions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('financial_dimension_value_id')->references('id')->on('financial_dimension_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['journal_line_id', 'financial_dimension_id'], 'jld_jl_fd_unique');
            $table->index(['financial_dimension_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_line_dimensions');
    }
};
