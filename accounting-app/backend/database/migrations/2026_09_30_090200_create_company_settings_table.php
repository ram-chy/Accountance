<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();

            // One settings row per company. unique() makes "exactly one" a
            // database fact rather than an application convention.
            $table->foreignId('company_id')
                ->unique()
                ->constrained('companies')
                ->cascadeOnDelete();

            /*
             | Document numbering. The prefix is stored but no counter exists
             | yet: invoice and quotation generation belongs to a later phase.
             | Only the configuration needed by the current foundation is
             | modelled, as the brief requires.
             */
            $table->string('invoice_number_prefix', 20)->nullable();
            $table->string('quotation_number_prefix', 20)->nullable();

            // Terms applied to new documents unless overridden per document.
            $table->unsignedSmallInteger('default_payment_terms_days')->default(0);

            /*
             | Currency reference, reserved for the fiscal period and currency
             | phase. Nullable and intentionally not a foreign key: the
             | currencies table does not exist yet and is not created here, so
             | this column records intent without inventing that engine.
             */
            $table->unsignedBigInteger('default_currency_id')->nullable();

            // 1-12. The fiscal year itself is configured in a later phase.
            $table->unsignedTinyInteger('default_fiscal_year_start_month')
                ->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
