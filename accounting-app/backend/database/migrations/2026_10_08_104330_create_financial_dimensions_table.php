<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_dimensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type', 50);
            $table->string('code', 50);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['company_id', 'type', 'code']);
            $table->index(['company_id', 'type', 'is_active']);
        });
        DB::statement("ALTER TABLE financial_dimensions ADD CONSTRAINT chk_financial_dimensions_type CHECK (type IN ('COST_CENTER','PROJECT','DEPARTMENT','LOCATION'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_dimensions');
    }
};
